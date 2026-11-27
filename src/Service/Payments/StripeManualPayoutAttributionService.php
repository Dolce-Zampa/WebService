<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Payments;

use Illuminate\Database\Capsule\Manager;

/** Records operator-provided attribution evidence; never moves money. */
final class StripeManualPayoutAttributionService
{
    private const MANUAL = 'stripe_connect_payout_manual_allocations';
    private const AUTO = 'stripe_connect_payout_allocations';

    public function __construct(private readonly Manager $db) {}

    public function idForKey(string $key): ?int
    {
        $id = $this->db->table(self::MANUAL)->where('idempotency_key', $key)->value('id');
        return $id === null ? null : (int) $id;
    }

    public function attribute(string $account, string $payout, string $transfer, int $amount, string $key, string $reason, string $evidence, string $operator, bool $dryRun = true): string
    {
        $this->identifiers($account, $payout, $transfer, $key, $reason, $evidence, $operator);
        if ($amount <= 0) throw new \InvalidArgumentException('Amount must be positive.');
        return $this->db->getConnection()->transaction(function () use ($account, $payout, $transfer, $amount, $key, $reason, $evidence, $operator, $dryRun): string {
            $payoutRow = $this->db->table('stripe_connect_payouts')->where('stripe_account_id', $account)->where('stripe_payout_id', $payout)->lockForUpdate()->first();
            $transferRow = $this->db->table('stripe_connect_transfers')->where('stripe_account_id', $account)->where('stripe_transfer_id', $transfer)->lockForUpdate()->first();
            if ($payoutRow === null || $transferRow === null) throw new \RuntimeException('Unknown payout or transfer.');
            $existing = $this->db->table(self::MANUAL)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if ($existing->stripe_account_id !== $account || $existing->stripe_payout_id !== $payout || $existing->stripe_transfer_id !== $transfer
                    || (int) $existing->direction !== 1 || (int) $existing->amount !== $amount || $existing->reason !== $reason
                    || $existing->evidence_reference !== $evidence || $existing->operator !== $operator) throw new \RuntimeException('Idempotency key already used for different evidence.');
                return 'already_recorded';
            }
            if ($payoutRow->status !== 'paid' || $payoutRow->manufacturer_id === null || $transferRow->status !== 'paid'
                || (int) $payoutRow->manufacturer_id !== (int) $transferRow->manufacturer_id
                || $payoutRow->currency !== $transferRow->currency || (int) $transferRow->order_id < 1) {
                throw new \RuntimeException('Payout and transfer are not compatible paid facts.');
            }
            if ($this->db->table('stripe_connect_transfer_reversals')->where('stripe_connect_transfer_id', $transferRow->id)->exists()) {
                throw new \RuntimeException('A transfer with reversals requires separate investigation.');
            }
            // An automatic payout is indivisible evidence; do not mix channels.
            if ($this->db->table(self::AUTO)->where('stripe_account_id', $account)->where('stripe_payout_id', $payout)->exists()
                || $this->activeAutoForTransfer($account, $transfer) > 0) {
                throw new \RuntimeException('Automatic attribution already exists.');
            }
            $usedPayout = $this->sumManual('a.stripe_payout_id', $payout, $account, false);
            $usedTransfer = $this->sumManual('a.stripe_transfer_id', $transfer, $account, true);
            if ($amount > (int) $payoutRow->amount - $usedPayout || $amount > (int) $transferRow->amount - $usedTransfer) {
                throw new \RuntimeException('Attribution exceeds the remaining payout or transfer.');
            }
            if ($dryRun) return 'preview';
            $this->db->table(self::MANUAL)->insert([
                'stripe_account_id' => $account, 'stripe_payout_id' => $payout, 'stripe_transfer_id' => $transfer,
                'order_id' => (int) $transferRow->order_id, 'manufacturer_id' => (int) $transferRow->manufacturer_id,
                'amount' => $amount, 'direction' => 1, 'reverses_allocation_id' => null, 'currency' => $payoutRow->currency,
                'idempotency_key' => $key, 'reason' => $reason, 'evidence_reference' => $evidence, 'operator' => $operator,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            return 'recorded';
        }, 3);
    }

    public function reverse(int $allocationId, string $key, string $reason, string $evidence, string $operator, bool $dryRun = true): string
    {
        if ($allocationId < 1) throw new \InvalidArgumentException('Invalid allocation.');
        $row = $this->db->table(self::MANUAL)->where('id', $allocationId)->first();
        if ($row === null || (int) $row->direction !== 1) throw new \RuntimeException('Unknown attribution.');
        $this->identifiers($row->stripe_account_id, $row->stripe_payout_id, $row->stripe_transfer_id, $key, $reason, $evidence, $operator);
        return $this->db->getConnection()->transaction(function () use ($row, $allocationId, $key, $reason, $evidence, $operator, $dryRun): string {
            $this->db->table('stripe_connect_payouts')->where('stripe_account_id', $row->stripe_account_id)->where('stripe_payout_id', $row->stripe_payout_id)->lockForUpdate()->first();
            $this->db->table('stripe_connect_transfers')->where('stripe_account_id', $row->stripe_account_id)->where('stripe_transfer_id', $row->stripe_transfer_id)->lockForUpdate()->first();
            $existing = $this->db->table(self::MANUAL)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if ((int) $existing->direction !== -1 || (int) $existing->reverses_allocation_id !== $allocationId || $existing->reason !== $reason
                    || $existing->evidence_reference !== $evidence || $existing->operator !== $operator) throw new \RuntimeException('Idempotency key already used for different evidence.');
                return 'already_recorded';
            }
            if ($this->db->table(self::MANUAL)->where('reverses_allocation_id', $allocationId)->exists()) throw new \RuntimeException('Attribution already reversed.');
            if ($dryRun) return 'preview';
            $this->db->table(self::MANUAL)->insert([
                'stripe_account_id' => $row->stripe_account_id, 'stripe_payout_id' => $row->stripe_payout_id,
                'stripe_transfer_id' => $row->stripe_transfer_id, 'order_id' => $row->order_id, 'manufacturer_id' => $row->manufacturer_id,
                'amount' => $row->amount, 'direction' => -1, 'reverses_allocation_id' => $allocationId, 'currency' => $row->currency,
                'idempotency_key' => $key, 'reason' => $reason, 'evidence_reference' => $evidence, 'operator' => $operator,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            return 'recorded';
        }, 3);
    }

    private function sumManual(string $field, string $id, string $account, bool $onlyActivePayouts): int
    {
        $query = $this->db->table(self::MANUAL . ' as a')->where('a.stripe_account_id', $account)->where($field, $id);
        if ($onlyActivePayouts) {
            $query->leftJoin('stripe_connect_payouts as p', function ($join): void {
                $join->on('p.stripe_account_id', '=', 'a.stripe_account_id')->on('p.stripe_payout_id', '=', 'a.stripe_payout_id');
            })->where(function ($where): void { $where->whereNull('p.id')->orWhereNotIn('p.status', ['failed', 'canceled']); });
        }
        return (int) ($query->selectRaw('COALESCE(SUM(a.amount * a.direction), 0) AS total')->value('total') ?? 0);
    }

    private function activeAutoForTransfer(string $account, string $transfer): int
    {
        return (int) $this->db->table(self::AUTO . ' as a')->leftJoin('stripe_connect_payouts as p', function ($join): void {
            $join->on('p.stripe_account_id', '=', 'a.stripe_account_id')->on('p.stripe_payout_id', '=', 'a.stripe_payout_id');
        })->where('a.stripe_account_id', $account)->where('a.stripe_transfer_id', $transfer)
            ->where(function ($where): void { $where->whereNull('p.id')->orWhereNotIn('p.status', ['failed', 'canceled']); })->count();
    }

    private function identifiers(string $account, string $payout, string $transfer, string $key, string $reason, string $evidence, string $operator): void
    {
        if (!preg_match('/^acct_[A-Za-z0-9]+$/D', $account) || !preg_match('/^po_[A-Za-z0-9]+$/D', $payout)
            || !preg_match('/^tr_[A-Za-z0-9]+$/D', $transfer)) throw new \InvalidArgumentException('Invalid Stripe identifier.');
        foreach ([[$key, 191], [$reason, 255], [$evidence, 191], [$operator, 100]] as [$value, $max]) {
            if (trim($value) === '' || strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) throw new \InvalidArgumentException('Invalid attribution evidence.');
        }
    }
}
