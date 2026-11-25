<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Payments;

use Illuminate\Database\Capsule\Manager;
use Stripe\StripeClient;

/** Only allocates a payout when the complete set can be proved without assumptions. */
final class StripePayoutOrderReconciliationService
{
    /**
     * Test hooks follow the same account contexts as the Stripe API.
     * @param null|\Closure(string,string): object $fetchPayout
     * @param null|\Closure(string,string): iterable<object> $listTransactions
     * @param null|\Closure(string): object $fetchTransfer
     */
    public function __construct(
        private readonly Manager $db,
        private readonly ?\Closure $fetchPayout = null,
        private readonly ?\Closure $listTransactions = null,
        private readonly ?\Closure $fetchTransfer = null,
    ) {}

    /** @return array{status:string, allocations:int} */
    public function reconcile(string $account, string $payoutId, bool $dryRun = true): array
    {
        if (!preg_match('/^acct_[A-Za-z0-9]+$/D', $account) || !preg_match('/^po_[A-Za-z0-9]+$/D', $payoutId)) {
            throw new \InvalidArgumentException('Invalid payout or connected account identifier.');
        }
        $local = $this->db->table('stripe_connect_payouts')->where('stripe_account_id', $account)->where('stripe_payout_id', $payoutId)->first();
        if ($local === null || $local->manufacturer_id === null || $local->status !== 'paid') {
            return ['status' => 'unavailable', 'allocations' => 0];
        }
        $stripe = null;
        if ($this->fetchPayout === null || $this->listTransactions === null || $this->fetchTransfer === null) {
            $key = (string) env('STRIPE_API_KEY', '');
            if ($key === '') throw new \RuntimeException('STRIPE_API_KEY is required.');
            $stripe = new StripeClient($key);
        }
        $payout = $this->fetchPayout !== null ? ($this->fetchPayout)($account, $payoutId)
            : $stripe->payouts->retrieve($payoutId, [], ['stripe_account' => $account]);
        if (($payout->id ?? null) !== $payoutId || ($payout->status ?? null) !== 'paid'
            || ($payout->amount ?? null) !== (int) $local->amount || strtoupper((string) ($payout->currency ?? '')) !== $local->currency) {
            return ['status' => 'unavailable', 'allocations' => 0];
        }
        if (($payout->automatic ?? null) !== true) return ['status' => 'manual', 'allocations' => 0];
        if (($payout->reconciliation_status ?? null) !== 'completed') return ['status' => 'not_ready', 'allocations' => 0];

        $transactions = $this->listTransactions !== null ? ($this->listTransactions)($account, $payoutId)
            : $stripe->balanceTransactions->all(['payout' => $payoutId, 'limit' => 100], ['stripe_account' => $account])->autoPagingIterator();
        $entries = [];
        $total = 0;
        foreach ($transactions as $tx) {
            $source = $this->id($tx->source ?? null);
            $id = $this->id($tx->id ?? null);
            $amount = $tx->amount ?? null;
            if (!str_starts_with($id, 'txn_') || !str_starts_with($source, 'py_')
                || !in_array($tx->type ?? null, ['payment', 'charge'], true)
                || !is_int($amount) || $amount <= 0 || ($tx->net ?? null) !== $amount || ($tx->fee ?? null) !== 0
                || strtoupper((string) ($tx->currency ?? '')) !== $local->currency || isset($entries[$source])) {
                return ['status' => 'unattributed', 'allocations' => 0];
            }
            $entries[$source] = ['stripe_balance_transaction_id' => $id, 'destination_payment_id' => $source, 'amount' => $amount];
            $total += $amount;
        }
        if ($entries === [] || $total !== (int) $local->amount) return ['status' => 'unattributed', 'allocations' => 0];

        $rows = $this->db->table('stripe_connect_transfers')->where('stripe_account_id', $account)
            ->where('manufacturer_id', $local->manufacturer_id)->where('status', 'paid')->whereNotNull('stripe_transfer_id')->get();
        $matches = [];
        foreach ($rows as $row) {
            $transfer = $this->fetchTransfer !== null ? ($this->fetchTransfer)((string) $row->stripe_transfer_id)
                : $stripe->transfers->retrieve((string) $row->stripe_transfer_id, []);
            $payment = $this->id($transfer->destination_payment ?? null);
            if (!isset($entries[$payment])) continue;
            $entry = $entries[$payment];
            if (isset($matches[$payment]) || $this->id($transfer->id ?? null) !== $row->stripe_transfer_id
                || $this->id($transfer->destination ?? null) !== $account || ($transfer->amount ?? null) !== $entry['amount']
                || ($transfer->amount_reversed ?? null) !== 0 || (int) $row->amount !== $entry['amount']
                || strtoupper((string) ($transfer->currency ?? '')) !== $local->currency || strtoupper((string) $row->currency) !== $local->currency
                || (int) $row->order_id < 1) {
                return ['status' => 'unattributed', 'allocations' => 0];
            }
            $matches[$payment] = $entry + [
                'stripe_account_id' => $account, 'stripe_payout_id' => $payoutId,
                'stripe_transfer_id' => (string) $row->stripe_transfer_id,
                'order_id' => (int) $row->order_id, 'manufacturer_id' => (int) $row->manufacturer_id,
                'currency' => $local->currency, 'created_at' => gmdate('Y-m-d H:i:s'),
            ];
        }
        if (count($matches) !== count($entries)) return ['status' => 'unattributed', 'allocations' => 0];
        return $this->db->getConnection()->transaction(function () use ($account, $payoutId, $local, $matches, $dryRun): array {
            $current = $this->db->table('stripe_connect_payouts')->where('stripe_account_id', $account)->where('stripe_payout_id', $payoutId)->lockForUpdate()->first();
            if ($current === null || $current->status !== 'paid' || (int) $current->amount !== (int) $local->amount
                || $current->currency !== $local->currency || (int) $current->manufacturer_id !== (int) $local->manufacturer_id) {
                return ['status' => 'unavailable', 'allocations' => 0];
            }
            $existing = $this->db->table('stripe_connect_payout_allocations')->where('stripe_account_id', $account)->where('stripe_payout_id', $payoutId)->get();
            if ($existing->isNotEmpty()) {
                return ['status' => 'already_recorded', 'allocations' => $existing->count()];
            }
            if ((int) $this->db->table('stripe_connect_payout_manual_allocations')->where('stripe_account_id', $account)->where('stripe_payout_id', $payoutId)->selectRaw('COALESCE(SUM(direction * amount), 0) AS allocated')->value('allocated') > 0) {
                return ['status' => 'unattributed', 'allocations' => 0];
            }
            $ids = array_column(array_values($matches), 'stripe_transfer_id');
            $lockedTransfers = $this->db->table('stripe_connect_transfers')->where('stripe_account_id', $account)
                ->whereIn('stripe_transfer_id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($lockedTransfers->count() !== count($ids)) return ['status' => 'unattributed', 'allocations' => 0];
            foreach ($lockedTransfers as $transfer) {
                $match = null;
                foreach ($matches as $candidate) {
                    if ($candidate['stripe_transfer_id'] === $transfer->stripe_transfer_id) { $match = $candidate; break; }
                }
                if ($match === null || $transfer->status !== 'paid' || (int) $transfer->amount !== $match['amount']
                    || (int) $transfer->order_id !== $match['order_id'] || (int) $transfer->manufacturer_id !== $match['manufacturer_id']
                    || strtoupper((string) $transfer->currency) !== $match['currency']
                    || $this->db->table('stripe_connect_transfer_reversals')->where('stripe_connect_transfer_id', $transfer->id)->exists()
                    || $this->hasActiveTransferAllocation($account, (string) $transfer->stripe_transfer_id)) {
                    return ['status' => 'unattributed', 'allocations' => 0];
                }
            }
            if (!$dryRun) $this->db->table('stripe_connect_payout_allocations')->insert(array_values($matches));
            return ['status' => $dryRun ? 'preview' : 'recorded', 'allocations' => count($matches)];
        }, 3);
    }

    private function hasActiveTransferAllocation(string $account, string $transfer): bool
    {
        $active = static function ($query): void {
            $query->whereNull('p.status')->orWhereNotIn('p.status', ['failed', 'canceled']);
        };
        $auto = $this->db->table('stripe_connect_payout_allocations as a')
            ->leftJoin('stripe_connect_payouts as p', function ($join): void {
                $join->on('p.stripe_account_id', '=', 'a.stripe_account_id')->on('p.stripe_payout_id', '=', 'a.stripe_payout_id');
            })->where('a.stripe_account_id', $account)->where('a.stripe_transfer_id', $transfer)->where($active)->exists();
        if ($auto) return true;
        $manual = $this->db->table('stripe_connect_payout_manual_allocations as a')
            ->leftJoin('stripe_connect_payouts as p', function ($join): void {
                $join->on('p.stripe_account_id', '=', 'a.stripe_account_id')->on('p.stripe_payout_id', '=', 'a.stripe_payout_id');
            })->where('a.stripe_account_id', $account)->where('a.stripe_transfer_id', $transfer)->where($active)
            ->selectRaw('COALESCE(SUM(a.direction * a.amount), 0) AS allocated')->value('allocated');
        return (int) $manual !== 0;
    }

    private function id(mixed $value): string
    {
        if (is_object($value)) $value = $value->id ?? null;
        return is_string($value) ? $value : '';
    }
}
