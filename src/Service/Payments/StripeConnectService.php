<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Payments;

use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
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
    private const REVERSALS = 'stripe_connect_transfer_reversals';
    private const DEFAULT_RETRY_BATCH_SIZE = 100;
    /**
     * A retry that keeps failing can mean that Stripe accepted the Transfer
     * but its response was lost, or that an operator must resolve an account
     * restriction. Stop automatic calls in both cases and preserve the
     * idempotency key for manual reconciliation.
     */
    private const MAX_TRANSFER_ATTEMPTS = 5;
    private const DEFAULT_COMMISSION_RATE = '20.00';
    private const MINIMUM_COMMISSION_EUR = '1.00';
    private const ACCOUNT_CREATION_IDEMPOTENCY_VERSION = 'v2';
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
        [$stored] = $this->ensureAccount($sellerId, $email);

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

    /**
     * Creates the Express connected account only. The caller can subsequently
     * request an onboarding link, which stays single-use and is never exposed
     * through this creation endpoint.
     *
     * @return array{created: bool, account: array<string, mixed>}
     */
    public function createAccount(int $sellerId, string $email): array
    {
        $this->assertSeller($sellerId);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('A valid seller email is required for Stripe Connect.');
        }
        [$stored, $created] = $this->ensureAccount($sellerId, $email);
        return ['created' => $created, 'account' => $this->publicAccount($stored)];
    }

    /**
     * Creates a single-use Express Dashboard link for the seller's own
     * connected account. The account id is always read from our mapping,
     * never accepted from the client.
     *
     * @return array{dashboard_url: string}
     */
    public function createDashboardLoginLink(int $sellerId): array
    {
        $this->assertSeller($sellerId);
        $stored = $this->db->table(self::ACCOUNTS)->where('manufacturer_id', $sellerId)->first();

        if ($stored === null) {
            throw new \RuntimeException('Stripe Connect account not found.');
        }

        if ((string) $stored->account_type !== 'express') {
            throw new \RuntimeException('Stripe Connect account cannot access the Express Dashboard.');
        }

        $link = $this->stripe->accounts->createLoginLink((string) $stored->stripe_account_id, []);
        $url = (string) ($link->url ?? '');

        if (filter_var($url, FILTER_VALIDATE_URL) === false || !str_starts_with($url, 'https://connect.stripe.com/')) {
            throw new \RuntimeException('Stripe returned an invalid Express Dashboard link.');
        }

        return ['dashboard_url' => $url];
    }

    /** @return array<string, mixed> */
    public function status(int $sellerId): array
    {
        $this->assertSeller($sellerId);
        $stored = $this->db->table(self::ACCOUNTS)->where('manufacturer_id', $sellerId)->first();
        if ($stored === null) {
            return ['connected' => false, 'ready_for_transfers' => false];
        }

        $account = $this->stripe->accounts->retrieve((string) $stored->stripe_account_id, []);
        $stored = $this->syncAccount($sellerId, $account);
        if ($this->isReadyForTransfers($stored)) {
            // The seller returning from Express onboarding is a reliable
            // fallback if an account.updated event was temporarily missed.
            $this->retryPendingTransfersForSeller($sellerId, $stored, $this->now());
        }

        return $this->publicAccount($stored);
    }

    /**
     * Handles a verified Stripe account.updated event. Unknown accounts are
     * ignored: only accounts already associated with a manufacturer can make
     * transfers in this platform.
     */
    public function handleAccountUpdated(\Stripe\StripeObject $account, DateTimeImmutable $occurredAt): int
    {
        if (!$this->enabled()) {
            return 0;
        }

        $accountId = $this->stripeId($account->id ?? null, 'account');
        $stored = $this->db->table(self::ACCOUNTS)->where('stripe_account_id', $accountId)->first();
        if ($stored === null) {
            return 0;
        }

        $sellerId = (int) $stored->manufacturer_id;
        $stored = $this->syncAccount($sellerId, $account);
        if (!$this->isReadyForTransfers($stored)) {
            return 0;
        }

        return $this->retryPendingTransfersForSeller($sellerId, $stored, $occurredAt);
    }

    /**
     * Scheduler fallback for pending transfers. It is deliberately bounded so
     * a single run cannot monopolize the worker when an account is offline.
     */
    public function retryPendingTransfers(int $limit = self::DEFAULT_RETRY_BATCH_SIZE): int
    {
        if (!$this->enabled()) {
            return 0;
        }
        if ($limit < 1) {
            throw new \InvalidArgumentException('Retry limit must be positive.');
        }

        // Pending allocations for an artisan who has not started Connect must
        // remain durable, but they must not consume the whole bounded batch
        // forever. account.updated and status() process those allocations as
        // soon as the account becomes ready.
        $pending = $this->db->table(self::TRANSFERS . ' as transfer')
            ->join(self::ACCOUNTS . ' as account', 'account.manufacturer_id', '=', 'transfer.manufacturer_id')
            ->where('transfer.status', 'pending')
            ->where('transfer.attempt_count', '<', self::MAX_TRANSFER_ATTEMPTS)
            ->where('account.details_submitted', true)
            ->where('account.payouts_enabled', true)
            ->where('account.transfers_enabled', true)
            ->select('transfer.*')
            ->orderBy('transfer.id')
            ->limit($limit)
            ->get();
        $settled = 0;
        foreach ($pending->groupBy('manufacturer_id') as $sellerId => $sellerTransfers) {
            $account = $this->readyAccount((int) $sellerId);
            if ($account === null) {
                continue;
            }
            $settled += $this->retryPendingTransferRows($sellerTransfers->all(), $account, $this->now());
        }

        return $settled;
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
        ?array $checkoutSnapshot = null,
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
        $paidCents = $this->paidAmountCents($paymentIntent);

        $allocations = $checkoutSnapshot === null
            ? $this->sellerAllocations($orderId, $paidCents)
            : $this->sellerAllocationsFromCheckoutSnapshot($checkoutSnapshot, $paidCents, $currency);
        foreach ($allocations as $allocation) {
            $sellerId = (int) $allocation['manufacturer_id'];
            $idempotencyKey = 'connect:transfer:' . hash('sha256', $paymentIntentId . ':' . $allocation['manufacturer_id']);
            $existing = $this->db->table(self::TRANSFERS)->where('idempotency_key', $idempotencyKey)->first();
            // An allocation is immutable once it has been stored. In
            // particular, a refund can reduce or cancel a still-pending
            // allocation before a seller completes onboarding; a duplicate
            // checkout webhook must never restore that amount.
            if ($existing !== null) {
                continue;
            }

            $storedAccount = $this->db->table(self::ACCOUNTS)->where('manufacturer_id', $sellerId)->first();
            $this->storeTransfer([
                'order_id' => $orderId,
                'manufacturer_id' => $sellerId,
                // A seller can be paid before starting Connect onboarding.
                // Keep the allocation durable and link it once an account is
                // available, instead of failing an otherwise valid order.
                'stripe_account_id' => $storedAccount?->stripe_account_id,
                'stripe_payment_intent_id' => $paymentIntentId,
                'stripe_charge_id' => $chargeId,
                'stripe_transfer_id' => null,
                'transfer_group' => $transferGroup,
                'idempotency_key' => $idempotencyKey,
                'amount' => $allocation['amount'],
                'original_amount' => $allocation['amount'],
                'currency' => $currency,
                'status' => 'pending',
                'failure_code' => 'seller_account_not_ready',
            ]);

            $account = $this->readyAccount($sellerId);
            if ($account !== null) {
                $this->retryPendingTransfersForSeller($sellerId, $account, $occurredAt);
            }
        }
    }

    /**
     * A platform refund does not reverse a Connect Transfer by itself. Once
     * Stripe has confirmed a refund, allocate the recoverable seller share
     * across the transfers for the original payment and create explicit
     * Transfer reversals. Allocations that have not reached a seller yet are
     * reduced or cancelled instead.
     */
    public function reconcileRefund(
        string $paymentIntentId,
        string $refundId,
        int $refundAmount,
        string $currency,
        string $refundStatus,
        DateTimeImmutable $occurredAt,
    ): void {
        if (!$this->enabled() || $refundStatus !== 'available') {
            return;
        }

        $this->assertConnectCase($paymentIntentId, $refundId, $refundAmount, $currency);
        $transfers = $this->transfersForPaymentIntent($paymentIntentId, $currency);
        if ($transfers === []) {
            return;
        }

        // A repeated refund event must not create a second reversal. Zero
        // amount rows are stored too, so this check also covers a refund whose
        // seller allocation rounds down to zero cents.
        if ($this->hasCase(self::REVERSALS, 'refund', $refundId)) {
            return;
        }

        $paymentAmount = $this->paymentIntentAmountCents($paymentIntentId);
        $refundedBefore = $this->refundedCentsBefore($paymentIntentId);
        $targetRefunded = min($paymentAmount, $refundedBefore + $refundAmount);
        $targetByTransfer = $this->proportionalTransferAmounts($transfers, $targetRefunded, $paymentAmount);
        $alreadyReversed = $this->reversedAmountsByTransfer('refund');

        foreach ($transfers as $transfer) {
            $transferId = (int) $transfer->id;
            $target = $targetByTransfer[$transferId] ?? 0;
            $additional = max(0, $target - ($alreadyReversed[$transferId] ?? 0));
            $this->storeReversal([
                'stripe_connect_transfer_id' => $transferId,
                'kind' => 'refund',
                'provider_case_id' => $refundId,
                'stripe_transfer_reversal_id' => null,
                'source_amount' => $refundAmount,
                'amount' => $additional,
                'currency' => strtoupper($currency),
                'status' => 'pending',
                'failure_code' => null,
                'idempotency_key' => $this->reversalIdempotencyKey('refund', $refundId, $transferId),
            ]);

            if ($additional === 0) {
                $this->setReversalStatus($transferId, 'refund', $refundId, 'not_required');
                continue;
            }

            if ((string) $transfer->status === 'pending') {
                $this->reducePendingTransferForRefund($transfer, $target);
                $this->setReversalStatus($transferId, 'refund', $refundId, 'cancelled');
                continue;
            }

            $this->reverseSettledTransfer($transfer, 'refund', $refundId, $additional, $occurredAt);
        }
    }

    /**
     * Charge disputes are deliberately recorded as recovery positions, not
     * as automatic seller debits. An operator must decide whether evidence,
     * a recovery, or a subsequent Transfer reversal is appropriate.
     */
    public function registerDispute(
        string $chargeId,
        string $disputeId,
        int $disputeAmount,
        string $currency,
    ): void {
        if (!$this->enabled()) {
            return;
        }

        $this->assertConnectCase($chargeId, $disputeId, $disputeAmount, $currency);
        $transfers = $this->transfersForCharge($chargeId, $currency);
        if ($transfers === [] || $this->hasCase(self::REVERSALS, 'dispute', $disputeId)) {
            return;
        }

        $paymentIntentId = (string) $transfers[0]->stripe_payment_intent_id;
        $paymentAmount = $this->paymentIntentAmountCents($paymentIntentId);
        $targetByTransfer = $this->proportionalTransferAmounts(
            $transfers,
            min($paymentAmount, $disputeAmount),
            $paymentAmount,
        );

        foreach ($transfers as $transfer) {
            $transferId = (int) $transfer->id;
            $this->storeReversal([
                'stripe_connect_transfer_id' => $transferId,
                'kind' => 'dispute',
                'provider_case_id' => $disputeId,
                'stripe_transfer_reversal_id' => null,
                'source_amount' => $disputeAmount,
                'amount' => $targetByTransfer[$transferId] ?? 0,
                'currency' => strtoupper($currency),
                'status' => 'pending_review',
                'failure_code' => null,
                'idempotency_key' => $this->reversalIdempotencyKey('dispute', $disputeId, $transferId),
            ]);
        }
    }

    /**
     * Explicit recovery entry point for an operator or a bounded scheduler.
     * It retries only recorded refund reversals; disputes remain review-only.
     */
    public function retryPendingRefundReversals(int $limit = self::DEFAULT_RETRY_BATCH_SIZE): int
    {
        if (!$this->enabled()) {
            return 0;
        }
        if ($limit < 1) {
            throw new \InvalidArgumentException('Retry limit must be positive.');
        }

        $pending = $this->db->table(self::REVERSALS . ' as reversal')
            ->join(self::TRANSFERS . ' as transfer', 'transfer.id', '=', 'reversal.stripe_connect_transfer_id')
            ->where('reversal.kind', 'refund')
            ->whereIn('reversal.status', ['pending', 'recovery_required'])
            ->where('reversal.amount', '>', 0)
            ->select(['reversal.*', 'transfer.stripe_transfer_id', 'transfer.order_id', 'transfer.manufacturer_id', 'transfer.stripe_account_id'])
            ->orderBy('reversal.id')
            ->limit($limit)
            ->get();

        $completed = 0;
        foreach ($pending as $reversal) {
            $transfer = (object) [
                'id' => (int) $reversal->stripe_connect_transfer_id,
                'stripe_transfer_id' => $reversal->stripe_transfer_id,
                'order_id' => $reversal->order_id,
                'manufacturer_id' => $reversal->manufacturer_id,
                'stripe_account_id' => $reversal->stripe_account_id,
            ];
            $this->reverseSettledTransfer(
                $transfer,
                'refund',
                (string) $reversal->provider_case_id,
                (int) $reversal->amount,
                $this->now(),
            );
            if ((string) $this->reversalStatus((int) $reversal->stripe_connect_transfer_id, 'refund', (string) $reversal->provider_case_id) === 'reversed') {
                ++$completed;
            }
        }

        return $completed;
    }

    public function enabled(): bool
    {
        return StripeConnectModule::enabled();
    }

    /** @return array{0: object, 1: bool} */
    private function ensureAccount(int $sellerId, string $email): array
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('A valid seller email is required for Stripe Connect.');
        }
        $stored = $this->db->table(self::ACCOUNTS)->where('manufacturer_id', $sellerId)->first();
        if ($stored !== null) {
            $account = $this->stripe->accounts->retrieve((string) $stored->stripe_account_id, []);
            return [$this->syncAccount($sellerId, $account), false];
        }

        // Express is the only supported account type. The country and seller
        // identity originate from server configuration and PrestaShop, never
        // from the request payload.
        $account = $this->stripe->accounts->create([
            'type' => 'express',
            'country' => strtoupper((string) env('STRIPE_CONNECT_COUNTRY', 'IT')),
            'email' => $email,
            // Card payments is requested with transfers for live Connect
            // eligibility. Checkout remains a platform charge and sellers only
            // receive transfers unless a future flow explicitly uses their
            // account to create a charge.
            'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers' => ['requested' => true],
            ],
            'metadata' => ['manufacturer_id' => (string) $sellerId],
        ], [
            // The version changes whenever the immutable account-create
            // payload changes. Stripe rejects the reuse of a key with a
            // different capability set.
            'idempotency_key' => 'connect:account:' . self::ACCOUNT_CREATION_IDEMPOTENCY_VERSION . ':manufacturer:' . $sellerId,
        ]);

        return [$this->syncAccount($sellerId, $account), true];
    }

    /** @return object|null */
    private function readyAccount(int $sellerId): ?object
    {
        $stored = $this->db->table(self::ACCOUNTS)->where('manufacturer_id', $sellerId)->first();
        if ($stored === null) {
            return null;
        }
        $account = $this->syncAccount($sellerId, $this->stripe->accounts->retrieve((string) $stored->stripe_account_id, []));
        return $this->isReadyForTransfers($account) ? $account : null;
    }

    /**
     * Splits the amount actually paid to Stripe, after removing shipping,
     * among sellers in proportion to their order lines. This deliberately
     * makes the payout independent from a technical PrestaShop cart rule
     * that may have been created only to reconcile its total with Checkout.
     *
     * @return list<array{manufacturer_id:int,amount:int}>
     */
    private function sellerAllocations(int $orderId, int $paidCents): array
    {
        $shippingCents = $this->shippingCentsForOrder($orderId);
        $productCents = $paidCents - $shippingCents;
        if ($productCents < 0) {
            throw new \RuntimeException('Stripe payment total is less than order shipping.');
        }

        $lines = $this->db->table('order_detail as detail')
            ->join('product as product', 'product.id_product', '=', 'detail.product_id')
            ->join('manufacturer_details as seller_detail', 'seller_detail.id_manufacturer', '=', 'product.id_manufacturer')
            ->where('detail.id_order', $orderId)
            ->select(['product.id_manufacturer as manufacturer_id', 'detail.total_price_tax_incl', 'seller_detail.commission'])
            ->get();
        if ($lines->isEmpty()) {
            throw new \RuntimeException('No seller order lines available for Stripe Connect transfer.');
        }

        /** @var array<int, array{gross:int,commission:int}> $sellers */
        $sellers = [];
        foreach ($lines as $line) {
            $sellerId = (int) $line->manufacturer_id;
            $grossCents = $this->decimalToCents((string) $line->total_price_tax_incl);
            $commissionRate = $this->commissionRate((string) ($line->commission ?? '20.00'));
            if ($sellerId < 1 || $grossCents < 0) {
                throw new \RuntimeException('Invalid seller transfer allocation.');
            }
            if (!isset($sellers[$sellerId])) {
                $sellers[$sellerId] = ['gross' => 0, 'commission' => $commissionRate];
            }
            if ($sellers[$sellerId]['commission'] !== $commissionRate) {
                throw new \RuntimeException('Seller commission is inconsistent within the order.');
            }
            $sellers[$sellerId]['gross'] += $grossCents;
        }

        $grossTotal = array_sum(array_column($sellers, 'gross'));
        if ($grossTotal < 1 && $productCents > 0) {
            throw new \RuntimeException('Order seller lines have no allocable product value.');
        }

        // Allocate whole cents deterministically. The final seller receives
        // the residual so the seller bases always add up to the actual paid
        // product amount rather than to a rounded approximation of it.
        ksort($sellers, SORT_NUMERIC);
        $remainingProductCents = $productCents;
        $remainingGrossCents = $grossTotal;
        $allocations = [];
        foreach ($sellers as $sellerId => $seller) {
            $sellerProductCents = $remainingGrossCents === 0
                ? 0
                : intdiv($remainingProductCents * $seller['gross'], $remainingGrossCents);
            $remainingProductCents -= $sellerProductCents;
            $remainingGrossCents -= $seller['gross'];
            $commissionCents = intdiv($sellerProductCents * $seller['commission'], 10000);
            $commissionCents = min(
                $sellerProductCents,
                max($this->minimumCommissionCents(), $commissionCents),
            );
            $allocations[] = [
                'manufacturer_id' => $sellerId,
                'amount' => $sellerProductCents - $commissionCents,
            ];
        }

        return $allocations;
    }

    /**
     * Uses the seller identities, product values and commission rates that
     * were frozen before Checkout. Product ownership and seller settings can
     * legitimately change after payment; those later changes must never alter
     * a transfer for an already-approved basket.
     *
     * @param array<string, mixed> $snapshot
     * @return list<array{manufacturer_id:int,amount:int}>
     */
    private function sellerAllocationsFromCheckoutSnapshot(array $snapshot, int $paidCents, string $currency): array
    {
        if (($snapshot['currency'] ?? null) !== $currency) {
            throw new \RuntimeException('Checkout snapshot currency differs from the Stripe payment.');
        }
        $totals = $snapshot['totals'] ?? null;
        $sellers = $snapshot['sellers'] ?? null;
        if (!is_array($totals) || !is_array($sellers)) {
            throw new \RuntimeException('Checkout snapshot has no seller allocation data.');
        }
        $shippingCents = $totals['shipping_cents'] ?? null;
        $grandTotalCents = $totals['grand_total_cents'] ?? null;
        if (!is_int($shippingCents) || $shippingCents < 0 || !is_int($grandTotalCents) || $grandTotalCents !== $paidCents) {
            throw new \RuntimeException('Checkout snapshot totals do not match the Stripe payment.');
        }
        $productCents = $paidCents - $shippingCents;
        if ($productCents < 0) {
            throw new \RuntimeException('Stripe payment total is less than checkout snapshot shipping.');
        }

        /** @var array<int, array{gross:int,commission:int}> $snapshotSellers */
        $snapshotSellers = [];
        foreach ($sellers as $seller) {
            if (!is_array($seller)) {
                throw new \RuntimeException('Checkout snapshot seller allocation is invalid.');
            }
            $sellerId = $seller['manufacturer_id'] ?? null;
            $grossCents = $seller['gross_cents'] ?? null;
            $commission = $seller['commission_basis_points'] ?? null;
            if (!is_int($sellerId) || $sellerId < 1 || !is_int($grossCents) || $grossCents < 0
                || !is_int($commission) || $commission < 0 || $commission > 10000 || isset($snapshotSellers[$sellerId])) {
                throw new \RuntimeException('Checkout snapshot seller allocation is invalid.');
            }
            $snapshotSellers[$sellerId] = ['gross' => $grossCents, 'commission' => $commission];
        }
        if ($snapshotSellers === []) {
            throw new \RuntimeException('Checkout snapshot has no seller allocations.');
        }

        $grossTotal = array_sum(array_column($snapshotSellers, 'gross'));
        if ($grossTotal < 1 && $productCents > 0) {
            throw new \RuntimeException('Checkout snapshot seller lines have no allocable product value.');
        }

        ksort($snapshotSellers, SORT_NUMERIC);
        $remainingProductCents = $productCents;
        $remainingGrossCents = $grossTotal;
        $allocations = [];
        foreach ($snapshotSellers as $sellerId => $seller) {
            $sellerProductCents = $remainingGrossCents === 0
                ? 0
                : intdiv($remainingProductCents * $seller['gross'], $remainingGrossCents);
            $remainingProductCents -= $sellerProductCents;
            $remainingGrossCents -= $seller['gross'];
            $commissionCents = intdiv($sellerProductCents * $seller['commission'], 10000);
            $commissionCents = min(
                $sellerProductCents,
                max($this->minimumCommissionCents(), $commissionCents),
            );
            $allocations[] = [
                'manufacturer_id' => $sellerId,
                'amount' => $sellerProductCents - $commissionCents,
            ];
        }

        return $allocations;
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
        $existing = $this->db->table(self::ACCOUNTS)->where('manufacturer_id', $sellerId)->first();
        if ($existing === null) {
            $values['manufacturer_id'] = $sellerId;
            $values['created_at'] = gmdate('Y-m-d H:i:s');
            $this->db->table(self::ACCOUNTS)->insert($values);
        } else {
            $this->db->table(self::ACCOUNTS)->where('manufacturer_id', $sellerId)->update($values);
        }
        return $this->db->table(self::ACCOUNTS)->where('manufacturer_id', $sellerId)->first();
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
                'stripe_account_id' => $values['stripe_account_id'],
                'status' => $values['status'],
                'failure_code' => $values['failure_code'],
                'updated_at' => $now,
            ]);
        }
    }

    /** @return list<object> */
    private function transfersForPaymentIntent(string $paymentIntentId, string $currency): array
    {
        return $this->db->table(self::TRANSFERS)
            ->where('stripe_payment_intent_id', $paymentIntentId)
            ->where('currency', strtoupper($currency))
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** @return list<object> */
    private function transfersForCharge(string $chargeId, string $currency): array
    {
        return $this->db->table(self::TRANSFERS)
            ->where('stripe_charge_id', $chargeId)
            ->where('currency', strtoupper($currency))
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function hasCase(string $table, string $kind, string $providerCaseId): bool
    {
        return $this->db->table($table)
            ->where('kind', $kind)
            ->where('provider_case_id', $providerCaseId)
            ->exists();
    }

    private function refundedCentsBefore(string $paymentIntentId): int
    {
        $amounts = $this->db->table(self::REVERSALS . ' as reversal')
            ->join(self::TRANSFERS . ' as transfer', 'transfer.id', '=', 'reversal.stripe_connect_transfer_id')
            ->where('reversal.kind', 'refund')
            ->where('transfer.stripe_payment_intent_id', $paymentIntentId)
            ->select(['reversal.provider_case_id', 'reversal.source_amount'])
            ->get();

        $byRefund = [];
        foreach ($amounts as $amount) {
            $byRefund[(string) $amount->provider_case_id] = max(
                $byRefund[(string) $amount->provider_case_id] ?? 0,
                (int) $amount->source_amount,
            );
        }

        return array_sum($byRefund);
    }

    /** @return array<int, int> */
    private function reversedAmountsByTransfer(string $kind): array
    {
        $rows = $this->db->table(self::REVERSALS)
            ->where('kind', $kind)
            ->select(['stripe_connect_transfer_id', 'amount'])
            ->get();
        $amounts = [];
        foreach ($rows as $row) {
            $transferId = (int) $row->stripe_connect_transfer_id;
            $amounts[$transferId] = ($amounts[$transferId] ?? 0) + (int) $row->amount;
        }

        return $amounts;
    }

    /**
     * @param list<object> $transfers
     * @return array<int, int> transfer id => desired cumulative recovery
     */
    private function proportionalTransferAmounts(array $transfers, int $customerAmount, int $paymentAmount): array
    {
        if ($paymentAmount < 1 || $customerAmount < 0) {
            throw new \RuntimeException('Invalid customer recovery amount.');
        }

        $allocations = [];
        foreach ($transfers as $transfer) {
            $base = $this->originalTransferAmount($transfer);
            if ($base < 0) {
                throw new \RuntimeException('Invalid Stripe Connect transfer amount.');
            }
            // The unallocated remainder is the platform's share (commission,
            // shipping, and Stripe fees); it must not be charged to a seller.
            $allocations[(int) $transfer->id] = intdiv($customerAmount * $base, $paymentAmount);
        }

        return $allocations;
    }

    private function originalTransferAmount(object $transfer): int
    {
        $value = $transfer->original_amount ?? $transfer->amount ?? null;
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            throw new \RuntimeException('Invalid Stripe Connect transfer amount.');
        }

        return (int) $value;
    }

    private function reducePendingTransferForRefund(object $transfer, int $cumulativeReversal): void
    {
        $originalAmount = $this->originalTransferAmount($transfer);
        $remaining = $originalAmount - $cumulativeReversal;
        if ($remaining < 0) {
            throw new \RuntimeException('Refund recovery exceeds the seller transfer amount.');
        }

        $this->db->table(self::TRANSFERS)->where('id', (int) $transfer->id)->update([
            'amount' => $remaining,
            'status' => $remaining === 0 ? 'cancelled' : 'pending',
            'failure_code' => $remaining === 0 ? 'refunded_before_transfer' : 'seller_account_not_ready',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function reverseSettledTransfer(object $transfer, string $kind, string $providerCaseId, int $amount, DateTimeImmutable $occurredAt): void
    {
        $transferId = (int) $transfer->id;
        $stripeTransferId = $this->stripeId($transfer->stripe_transfer_id ?? null, 'transfer');
        $idempotencyKey = $this->reversalIdempotencyKey($kind, $providerCaseId, $transferId);

        try {
            $reversal = $this->stripe->transfers->createReversal($stripeTransferId, [
                'amount' => $amount,
                'metadata' => [
                    'order_id' => (string) $transfer->order_id,
                    'manufacturer_id' => (string) $transfer->manufacturer_id,
                    'recovery_kind' => $kind,
                    'provider_case_id' => $providerCaseId,
                ],
            ], ['idempotency_key' => $idempotencyKey]);

            $reversalId = $this->stripeId($reversal->id ?? null, 'transfer reversal');
            $this->updateReversal($transferId, $kind, $providerCaseId, [
                'stripe_transfer_reversal_id' => $reversalId,
                'status' => 'reversed',
                'failure_code' => null,
            ]);
            $this->recordTransferReversal($transfer, $kind, $providerCaseId, $reversalId, $amount, $occurredAt);
        } catch (\Throwable $exception) {
            $this->updateReversal($transferId, $kind, $providerCaseId, [
                'status' => 'recovery_required',
                'failure_code' => $this->failureCode($exception),
            ]);
            Log::warning('Stripe Connect transfer recovery requires review.', [
                'manufacturer_id' => (int) $transfer->manufacturer_id,
                'order_id' => (int) $transfer->order_id,
                'recovery_kind' => $kind,
                'failure_code' => $this->failureCode($exception),
            ]);
        }
    }

    private function recordTransferReversal(object $transfer, string $kind, string $providerCaseId, string $reversalId, int $amount, DateTimeImmutable $occurredAt): void
    {
        if ($this->ledger === null) {
            return;
        }

        $originalPayout = $this->ledger->findByProviderTransaction('stripe', (string) $transfer->stripe_transfer_id);
        if ($originalPayout === null) {
            return;
        }

        $this->ledger->recordReversal((int) $originalPayout->id, FinancialMovement::fromArray([
            'type' => 'payout',
            'status' => 'reversed',
            'amount' => $this->centsToDecimal($amount),
            'currency' => (string) $originalPayout->currency,
            'occurred_at' => $occurredAt,
            'order_id' => $this->positiveInteger($originalPayout->order_id),
            'artisan_id' => $this->positiveInteger($originalPayout->artisan_id),
            'provider' => 'stripe',
            'provider_account_id' => $this->stripeOptionalIdentifier($originalPayout->provider_account_id),
            'provider_transaction_id' => $reversalId,
            'provider_event_id' => $reversalId,
            'source_event_type' => 'stripe.transfer.reversal.created',
            'source_event_id' => $reversalId,
            'metadata' => [
                'stripe_transfer_id' => (string) $transfer->stripe_transfer_id,
                'recovery_kind' => $kind,
                'provider_case_id' => $providerCaseId,
            ],
        ]), 'stripe:transfer-reversal:' . hash('sha256', $reversalId));
    }

    /** @param array<string, mixed> $values */
    private function storeReversal(array $values): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $values['created_at'] = $now;
        $values['updated_at'] = $now;
        try {
            $this->db->table(self::REVERSALS)->insert($values);
        } catch (QueryException) {
            // A concurrent delivery of the same Stripe event owns the same
            // idempotency key. Its existing record is the source of truth.
        }
    }

    /** @param array<string, mixed> $values */
    private function updateReversal(int $transferId, string $kind, string $providerCaseId, array $values): void
    {
        $values['updated_at'] = gmdate('Y-m-d H:i:s');
        $this->db->table(self::REVERSALS)
            ->where('stripe_connect_transfer_id', $transferId)
            ->where('kind', $kind)
            ->where('provider_case_id', $providerCaseId)
            ->update($values);
    }

    private function setReversalStatus(int $transferId, string $kind, string $providerCaseId, string $status): void
    {
        $this->updateReversal($transferId, $kind, $providerCaseId, ['status' => $status]);
    }

    private function reversalStatus(int $transferId, string $kind, string $providerCaseId): ?string
    {
        return $this->db->table(self::REVERSALS)
            ->where('stripe_connect_transfer_id', $transferId)
            ->where('kind', $kind)
            ->where('provider_case_id', $providerCaseId)
            ->value('status');
    }

    private function reversalIdempotencyKey(string $kind, string $providerCaseId, int $transferId): string
    {
        return 'connect:' . $kind . '-reversal:' . hash('sha256', $providerCaseId . ':' . $transferId);
    }

    private function paymentIntentAmountCents(string $paymentIntentId): int
    {
        $paymentIntent = $this->stripe->paymentIntents->retrieve($paymentIntentId, []);
        return $this->paidAmountCents($paymentIntent);
    }

    private function assertConnectCase(string $paymentReference, string $caseId, int $amount, string $currency): void
    {
        if ($paymentReference === '' || strlen($paymentReference) > 191 || $caseId === '' || strlen($caseId) > 191 || $amount < 1 || !preg_match('/^[A-Z]{3}$/', strtoupper($currency))) {
            throw new \InvalidArgumentException('Invalid Stripe Connect recovery correlation.');
        }
    }

    /**
     * @param list<object> $pendingTransfers
     */
    private function retryPendingTransferRows(array $pendingTransfers, object $account, DateTimeImmutable $occurredAt): int
    {
        $settled = 0;
        foreach ($pendingTransfers as $pending) {
            try {
                $this->transferPending($pending, $account, $occurredAt);
                ++$settled;
            } catch (\Throwable $exception) {
                $failureCode = $this->failureCode($exception);
                $status = $this->markTransferPending($pending, (string) $account->stripe_account_id, $failureCode);
                Log::warning($status === 'manual_review'
                    ? 'Stripe Connect transfer needs manual reconciliation after bounded retries.'
                    : 'Stripe Connect transfer left pending for a later retry.', [
                    'manufacturer_id' => (int) $pending->manufacturer_id,
                    'order_id' => (int) $pending->order_id,
                    'failure_code' => $failureCode,
                    'status' => $status,
                ]);
            }
        }

        return $settled;
    }

    private function retryPendingTransfersForSeller(int $sellerId, object $account, DateTimeImmutable $occurredAt): int
    {
        if (!$this->isReadyForTransfers($account)) {
            return 0;
        }

        $pending = $this->db->table(self::TRANSFERS)
            ->where('manufacturer_id', $sellerId)
            ->where('status', 'pending')
            ->where('attempt_count', '<', self::MAX_TRANSFER_ATTEMPTS)
            ->orderBy('id')
            ->limit(self::DEFAULT_RETRY_BATCH_SIZE)
            ->get()
            ->all();

        return $this->retryPendingTransferRows($pending, $account, $occurredAt);
    }

    private function transferPending(object $pending, object $account, DateTimeImmutable $occurredAt): void
    {
        $orderId = (int) $pending->order_id;
        $sellerId = (int) $pending->manufacturer_id;
        $amount = (int) $pending->amount;
        $currency = strtoupper((string) $pending->currency);
        $paymentIntentId = $this->stripeId($pending->stripe_payment_intent_id ?? null, 'payment intent');
        $chargeId = $this->stripeId($pending->stripe_charge_id ?? null, 'charge');
        $accountId = $this->stripeId($account->stripe_account_id ?? null, 'account');
        $idempotencyKey = (string) ($pending->idempotency_key ?? '');

        if ($orderId < 1 || $sellerId < 1 || $amount < 1 || !preg_match('/^[A-Z]{3}$/', $currency) || $idempotencyKey === '' || strlen($idempotencyKey) > 191) {
            throw new \RuntimeException('Pending Stripe Connect transfer is invalid.');
        }

        $transfer = $this->stripe->transfers->create([
            'amount' => $amount,
            'currency' => strtolower($currency),
            'destination' => $accountId,
            'source_transaction' => $chargeId,
            'transfer_group' => (string) $pending->transfer_group,
            'metadata' => [
                'order_id' => (string) $orderId,
                'manufacturer_id' => (string) $sellerId,
            ],
        ], ['idempotency_key' => $idempotencyKey]);

        $this->storeTransfer([
            'order_id' => $orderId,
            'manufacturer_id' => $sellerId,
            'stripe_account_id' => $accountId,
            'stripe_payment_intent_id' => $paymentIntentId,
            'stripe_charge_id' => $chargeId,
            'stripe_transfer_id' => $this->stripeId($transfer->id ?? null, 'transfer'),
            'transfer_group' => (string) $pending->transfer_group,
            'idempotency_key' => $idempotencyKey,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'paid',
            'failure_code' => null,
        ]);
        $this->recordPayout(
            $orderId,
            ['manufacturer_id' => $sellerId, 'amount' => $amount],
            $account,
            $transfer,
            $paymentIntentId,
            $occurredAt,
            $currency,
        );
    }

    private function markTransferPending(object $pending, string $accountId, string $failureCode): string
    {
        $attemptCount = (int) ($pending->attempt_count ?? 0) + 1;
        $status = $attemptCount >= self::MAX_TRANSFER_ATTEMPTS ? 'manual_review' : 'pending';
        $this->db->table(self::TRANSFERS)
            ->where('idempotency_key', (string) $pending->idempotency_key)
            ->update([
                'stripe_account_id' => $accountId,
                'status' => $status,
                'failure_code' => $failureCode,
                'attempt_count' => $attemptCount,
                'last_attempted_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

        return $status;
    }

    private function isReadyForTransfers(object $account): bool
    {
        return (bool) $account->details_submitted
            && (bool) $account->payouts_enabled
            && (bool) $account->transfers_enabled;
    }

    private function failureCode(\Throwable $exception): string
    {
        $stripeCode = $exception instanceof \Stripe\Exception\ApiErrorException ? $exception->getStripeCode() : null;
        if (is_string($stripeCode) && preg_match('/^[A-Za-z0-9_-]+$/', $stripeCode)) {
            return substr('stripe_' . $stripeCode, 0, 100);
        }

        return 'transfer_retry_required';
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** @param array{manufacturer_id:int,amount:int} $allocation */
    private function recordPayout(int $orderId, array $allocation, object $account, \Stripe\StripeObject $transfer, string $paymentIntentId, DateTimeImmutable $occurredAt, string $currency): void
    {
        if ($this->ledger === null) return;
        $transferId = $this->stripeId($transfer->id ?? null, 'transfer');
        $this->ledger->record(FinancialMovement::fromArray([
            'type' => 'payout', 'status' => 'paid', 'amount' => '-' . $this->centsToDecimal($allocation['amount']),
            'currency' => $currency, 'occurred_at' => $occurredAt, 'order_id' => $orderId,
            'artisan_id' => $allocation['manufacturer_id'], 'provider' => 'stripe',
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

    private function paidAmountCents(\Stripe\StripeObject $paymentIntent): int
    {
        $data = $paymentIntent->toArray();
        foreach (['amount_received', 'amount'] as $field) {
            $value = $data[$field] ?? null;
            if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
                return (int) $value;
            }
        }

        throw new \RuntimeException('Stripe payment intent has no valid paid amount.');
    }

    private function shippingCentsForOrder(int $orderId): int
    {
        $shipping = $this->db->table('orders')
            ->where('id_order', $orderId)
            ->value('total_shipping_tax_incl');
        if ($shipping === null) {
            throw new \RuntimeException('Order shipping total is not available for Stripe Connect transfer.');
        }

        return $this->decimalToCents((string) $shipping);
    }

    private function decimalToCents(string $amount): int
    {
        if (!preg_match('/^\d+(?:\.\d{1,6})?$/', $amount)) throw new \RuntimeException('Invalid order amount for transfer.');
        return (int) bcmul($amount, '100', 0);
    }

    private function commissionRate(string $rate): int
    {
        // The legacy manufacturer_details column was introduced with a 0.00
        // default. Treat that old unset value as the platform's 20% default.
        if ($rate === '' || !preg_match('/^\d+(?:\.\d{1,2})?$/', $rate) || bccomp($rate, '0', 2) === 0) {
            $rate = (string) env('STRIPE_CONNECT_DEFAULT_COMMISSION_RATE', self::DEFAULT_COMMISSION_RATE);
        }
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $rate)) {
            throw new \RuntimeException('Invalid default seller commission rate.');
        }
        $basisPoints = (int) bcmul($rate, '100', 0);
        if ($basisPoints < 0 || $basisPoints > 10000) throw new \RuntimeException('Invalid seller commission rate.');
        return $basisPoints;
    }

    private function minimumCommissionCents(): int
    {
        $minimum = (string) env('STRIPE_CONNECT_MINIMUM_COMMISSION_EUR', self::MINIMUM_COMMISSION_EUR);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $minimum)) {
            throw new \RuntimeException('Invalid minimum seller commission.');
        }

        return $this->decimalToCents($minimum);
    }

    private function centsToDecimal(int $amount): string { return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT); }
    private function assertSeller(int $sellerId): void { if ($sellerId < 1) throw new \InvalidArgumentException('Invalid seller.'); }
    private function assertAbsoluteUrl(string $url, string $field): void { if (filter_var($url, FILTER_VALIDATE_URL) === false) throw new \InvalidArgumentException('Invalid ' . $field . '.'); }
    private function stripeId(mixed $value, string $kind): string { $id = $value instanceof \Stripe\StripeObject ? $value->id : $value; if (!is_string($id) || $id === '' || strlen($id) > 191) throw new \RuntimeException('Stripe ' . $kind . ' identifier is invalid.'); return $id; }
    private function stripeOptionalIdentifier(mixed $value): ?string { if (!is_string($value) && !is_int($value)) return null; $id = trim((string) $value); return $id === '' || strlen($id) > 191 ? null : $id; }
    private function positiveInteger(mixed $value): ?int { return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false ? null : (int) $value; }
}
