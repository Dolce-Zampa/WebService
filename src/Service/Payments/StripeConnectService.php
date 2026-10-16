<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Payments;

use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\QueryException;
use PS\Webservice\Domain\Financial\FinancialMovement;
use PS\Webservice\Service\Financial\FinancialLedgerService;

/**
 * Platform-charge / separate-transfer implementation for Stripe Connect.
 * Seller and amount data is always loaded from persisted PrestaShop records;
 * it is never accepted from the checkout client or Stripe metadata.
 */
final class StripeConnectService
{
    private const ACCOUNTS = 'stripe_connect_accounts';
    private const TRANSFERS = 'stripe_connect_transfers';
    private readonly \Stripe\StripeClient $stripe;

    public function __construct(
        private readonly Manager $db,
        private readonly ?FinancialLedgerService $ledger = null,
        ?\Stripe\StripeClient $stripe = null,
    ) {
        $key = (string) env('STRIPE_API_KEY', '');
        if ($stripe === null && $key === '') {
            throw new \RuntimeException('STRIPE_API_KEY is required for Stripe Connect.');
        }
        $this->stripe = $stripe ?? new \Stripe\StripeClient($key);
    }

    /** @return array<string, mixed> */
    public function beginOnboarding(int $sellerId, string $email, string $returnUrl, string $refreshUrl): array
    {
        $this->assertSeller($sellerId);
        $this->assertAbsoluteUrl($returnUrl, 'return URL');
        $this->assertAbsoluteUrl($refreshUrl, 'refresh URL');

        $stored = $this->db->table(self::ACCOUNTS)->where('seller_id', $sellerId)->first();
        if ($stored === null) {
            $account = $this->stripe->accounts->create([
                'type' => 'express',
                'country' => strtoupper((string) env('STRIPE_CONNECT_COUNTRY', 'IT')),
                'email' => $email,
                'capabilities' => ['transfers' => ['requested' => true]],
                'metadata' => ['seller_id' => (string) $sellerId],
            ], ['idempotency_key' => 'connect:account:seller:' . $sellerId]);
            $stored = $this->syncAccount($sellerId, $account);
        } else {
            $account = $this->stripe->accounts->retrieve((string) $stored->stripe_account_id, []);
            $stored = $this->syncAccount($sellerId, $account);
        }

        $link = $this->stripe->accountLinks->create([
            'account' => (string) $stored->stripe_account_id,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ]);

        return [
            'onboarding_url' => (string) $link->url,
            'expires_at' => (int) $link->expires_at,
            'account' => $this->publicAccount($stored),
        ];
    }

    /** @return array<string, mixed> */
    public function status(int $sellerId): array
    {
        $this->assertSeller($sellerId);
        $stored = $this->db->table(self::ACCOUNTS)->where('seller_id', $sellerId)->first();
        if ($stored === null) {
            return ['connected' => false, 'ready_for_transfers' => false];
        }

        $account = $this->stripe->accounts->retrieve((string) $stored->stripe_account_id, []);
        return $this->publicAccount($this->syncAccount($sellerId, $account));
    }

    /**
     * Creates one idempotent transfer per seller after an already-confirmed
     * platform Checkout payment. Returns quietly when Connect rollout is off.
     */
    public function createTransfersForOrder(
        int $orderId,
        string $paymentIntentId,
        string $transferGroup,
        DateTimeImmutable $occurredAt,
    ): void {
        if (!$this->enabled()) {
            return;
        }
        if ($orderId < 1 || !preg_match('/^pi_[A-Za-z0-9]+$/', $paymentIntentId) || $transferGroup === '') {
            throw new \InvalidArgumentException('Invalid Stripe Connect transfer correlation.');
        }

        $paymentIntent = $this->stripe->paymentIntents->retrieve($paymentIntentId, ['expand' => ['latest_charge']]);
        $chargeId = $this->stripeId($paymentIntent->latest_charge ?? null, 'charge');
        $currency = strtoupper((string) $paymentIntent->currency);
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \RuntimeException('Stripe payment intent has no valid currency.');
        }

        foreach ($this->sellerAllocations($orderId) as $allocation) {
            $account = $this->readyAccount((int) $allocation['seller_id']);
            $idempotencyKey = 'connect:transfer:' . hash('sha256', $paymentIntentId . ':' . $allocation['seller_id']);
            $existing = $this->db->table(self::TRANSFERS)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null && $existing->status === 'paid') {
                continue;
            }

            $transfer = $this->stripe->transfers->create([
                'amount' => $allocation['amount'],
                'currency' => strtolower($currency),
                'destination' => $account->stripe_account_id,
                'source_transaction' => $chargeId,
                'transfer_group' => $transferGroup,
                'metadata' => [
                    'order_id' => (string) $orderId,
                    'seller_id' => (string) $allocation['seller_id'],
                ],
            ], ['idempotency_key' => $idempotencyKey]);

            $this->storeTransfer([
                'order_id' => $orderId,
                'seller_id' => $allocation['seller_id'],
                'stripe_account_id' => $account->stripe_account_id,
                'stripe_payment_intent_id' => $paymentIntentId,
                'stripe_charge_id' => $chargeId,
                'stripe_transfer_id' => $this->stripeId($transfer->id ?? null, 'transfer'),
                'transfer_group' => $transferGroup,
                'idempotency_key' => $idempotencyKey,
                'amount' => $allocation['amount'],
                'currency' => $currency,
                'status' => 'paid',
                'failure_code' => null,
            ]);
            $this->recordPayout($orderId, $allocation, $account, $transfer, $paymentIntentId, $occurredAt, $currency);
        }
    }

    public function enabled(): bool
    {
        return filter_var(env('STRIPE_CONNECT_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
    }

    /** @return object */
    private function readyAccount(int $sellerId): object
    {
        $stored = $this->db->table(self::ACCOUNTS)->where('seller_id', $sellerId)->first();
        if ($stored === null) {
            throw new \RuntimeException('Seller has no Stripe Connect account.');
        }
        $account = $this->syncAccount($sellerId, $this->stripe->accounts->retrieve((string) $stored->stripe_account_id, []));
        if (!$account->details_submitted || !$account->payouts_enabled || !$account->transfers_enabled) {
            throw new \RuntimeException('Seller Stripe Connect account is not ready for transfers.');
        }
        return $account;
    }

    /** @return list<array{seller_id:int,amount:int}> */
    private function sellerAllocations(int $orderId): array
    {
        $lines = $this->db->table('order_detail as detail')
            ->join('product as product', 'product.id_product', '=', 'detail.product_id')
            ->join('manufacturer_details as seller_detail', 'seller_detail.id_manufacturer', '=', 'product.id_manufacturer')
            ->where('detail.id_order', $orderId)
            ->select(['product.id_manufacturer as seller_id', 'detail.total_price_tax_incl', 'seller_detail.commission'])
            ->get();
        if ($lines->isEmpty()) {
            throw new \RuntimeException('No seller order lines available for Stripe Connect transfer.');
        }

        $amounts = [];
        foreach ($lines as $line) {
            $sellerId = (int) $line->seller_id;
            $grossCents = $this->decimalToCents((string) $line->total_price_tax_incl);
            $commissionRate = $this->commissionRate((string) ($line->commission ?? '20.00'));
            $netCents = intdiv($grossCents * (10000 - $commissionRate), 10000);
            if ($sellerId < 1 || $netCents < 0) {
                throw new \RuntimeException('Invalid seller transfer allocation.');
            }
            $amounts[$sellerId] = ($amounts[$sellerId] ?? 0) + $netCents;
        }

        return array_map(
            static fn (int $sellerId, int $amount): array => ['seller_id' => $sellerId, 'amount' => $amount],
            array_keys($amounts),
            array_values($amounts),
        );
    }

    /** @return object */
    private function syncAccount(int $sellerId, \Stripe\StripeObject $account): object
    {
        $data = $account->toArray();
        $capabilities = is_array($data['capabilities'] ?? null) ? $data['capabilities'] : [];
        $requirements = is_array($data['requirements'] ?? null) ? $data['requirements'] : [];
        $values = [
            'stripe_account_id' => $this->stripeId($data['id'] ?? null, 'account'),
            'account_type' => (string) ($data['type'] ?? 'express'),
            'country' => strtoupper((string) ($data['country'] ?? 'IT')),
            'details_submitted' => (bool) ($data['details_submitted'] ?? false),
            'charges_enabled' => (bool) ($data['charges_enabled'] ?? false),
            'payouts_enabled' => (bool) ($data['payouts_enabled'] ?? false),
            'transfers_enabled' => ($capabilities['transfers'] ?? null) === 'active',
            'requirements_due' => isset($requirements['currently_due']) ? json_encode(array_values((array) $requirements['currently_due']), JSON_THROW_ON_ERROR) : null,
            'last_synced_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        $existing = $this->db->table(self::ACCOUNTS)->where('seller_id', $sellerId)->first();
        if ($existing === null) {
            $values['seller_id'] = $sellerId;
            $values['created_at'] = gmdate('Y-m-d H:i:s');
            $this->db->table(self::ACCOUNTS)->insert($values);
        } else {
            $this->db->table(self::ACCOUNTS)->where('seller_id', $sellerId)->update($values);
        }
        return $this->db->table(self::ACCOUNTS)->where('seller_id', $sellerId)->first();
    }

    /** @param array<string, mixed> $values */
    private function storeTransfer(array $values): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $values['created_at'] = $now;
        $values['updated_at'] = $now;
        try {
            $this->db->table(self::TRANSFERS)->insert($values);
        } catch (QueryException) {
            $this->db->table(self::TRANSFERS)->where('idempotency_key', $values['idempotency_key'])->update([
                'stripe_transfer_id' => $values['stripe_transfer_id'],
                'stripe_charge_id' => $values['stripe_charge_id'],
                'status' => $values['status'],
                'failure_code' => null,
                'updated_at' => $now,
            ]);
        }
    }

    /** @param array{seller_id:int,amount:int} $allocation */
    private function recordPayout(int $orderId, array $allocation, object $account, \Stripe\StripeObject $transfer, string $paymentIntentId, DateTimeImmutable $occurredAt, string $currency): void
    {
        if ($this->ledger === null) return;
        $transferId = $this->stripeId($transfer->id ?? null, 'transfer');
        $this->ledger->record(FinancialMovement::fromArray([
            'type' => 'payout', 'status' => 'paid', 'amount' => '-' . $this->centsToDecimal($allocation['amount']),
            'currency' => $currency, 'occurred_at' => $occurredAt, 'order_id' => $orderId,
            'artisan_id' => $allocation['seller_id'], 'provider' => 'stripe',
            'provider_account_id' => (string) $account->stripe_account_id,
            'provider_transaction_id' => $transferId, 'provider_event_id' => $transferId,
            'source_event_type' => 'stripe.transfer.created', 'source_event_id' => $transferId,
            'metadata' => ['stripe_payment_intent_id' => $paymentIntentId, 'stripe_transfer_id' => $transferId],
            'settled_at' => $occurredAt,
        ]), 'stripe:transfer:' . hash('sha256', $transferId));
    }

    /** @return array<string, mixed> */
    private function publicAccount(object $account): array
    {
        $requirements = $account->requirements_due === null ? [] : json_decode((string) $account->requirements_due, true, 512, JSON_THROW_ON_ERROR);
        return [
            'connected' => true, 'account_id' => (string) $account->stripe_account_id,
            'details_submitted' => (bool) $account->details_submitted,
            'charges_enabled' => (bool) $account->charges_enabled,
            'payouts_enabled' => (bool) $account->payouts_enabled,
            'transfers_enabled' => (bool) $account->transfers_enabled,
            'ready_for_transfers' => (bool) $account->details_submitted && (bool) $account->payouts_enabled && (bool) $account->transfers_enabled,
            'requirements_due' => $requirements,
        ];
    }

    private function decimalToCents(string $amount): int
    {
        if (!preg_match('/^\d+(?:\.\d{1,6})?$/', $amount)) throw new \RuntimeException('Invalid order amount for transfer.');
        return (int) bcmul($amount, '100', 0);
    }

    private function commissionRate(string $rate): int
    {
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $rate)) return 2000;
        $basisPoints = (int) bcmul($rate, '100', 0);
        if ($basisPoints < 0 || $basisPoints > 10000) throw new \RuntimeException('Invalid seller commission rate.');
        return $basisPoints;
    }

    private function centsToDecimal(int $amount): string { return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT); }
    private function assertSeller(int $sellerId): void { if ($sellerId < 1) throw new \InvalidArgumentException('Invalid seller.'); }
    private function assertAbsoluteUrl(string $url, string $field): void { if (filter_var($url, FILTER_VALIDATE_URL) === false) throw new \InvalidArgumentException('Invalid ' . $field . '.'); }
    private function stripeId(mixed $value, string $kind): string { $id = $value instanceof \Stripe\StripeObject ? $value->id : $value; if (!is_string($id) || $id === '' || strlen($id) > 191) throw new \RuntimeException('Stripe ' . $kind . ' identifier is invalid.'); return $id; }
}
