<?php
declare(strict_types=1);

namespace PS\Webservice\Service\GiftCard;

use Illuminate\Support\Facades\Cache;
use PS\Webservice\Service\Payments\CheckoutSnapshotService;
use PS\Webservice\Service\Payments\StripeConnectService;
use PS\Webservice\Domain\Financial\FinancialMovement;
use PS\Webservice\Service\Financial\FinancialLedgerService;
use PS\Webservice\Service\Financial\SellerSaleNotificationService;
use PS\Webservice\Service\PS\Order;

/** Resumable settlement of a verified checkout; the gift card code is never stored. */
final class GiftCardSettlementService
{
    public function __construct(
        private readonly GiftCardClient $giftCards,
        private readonly CheckoutSnapshotService $snapshots,
        private readonly Order $orders,
        private readonly StripeConnectService $stripeConnect,
        private readonly ?FinancialLedgerService $ledger = null,
        private readonly ?SellerSaleNotificationService $sellerNotifications = null,
    ) {}

    public function settle(string $snapshotId): int
    {
        $snapshot = $this->snapshots->verifiedGiftCardSnapshot($snapshotId);
        $payload = $snapshot['payload'];
        $cartId = (int) ($payload['cart_id'] ?? 0);
        $gift = $payload['gift_card'] ?? null;
        $reservationId = is_array($gift) ? ($gift['reservation_id'] ?? null) : null;
        if ($cartId < 1 || !is_string($reservationId) || !preg_match('/^[a-f0-9]{32}$/D', $reservationId)) {
            throw new \RuntimeException('Gift card checkout data is invalid.');
        }

        $cache = Cache::getFacadeRoot();
        $store = is_object($cache) && method_exists($cache, 'getStore') ? $cache->getStore() : null;
        $isDevelopment = in_array(strtolower((string) env('APP_ENV', 'production')), ['dev', 'development', 'local', 'test', 'testing'], true);
        if (!is_object($store) || !method_exists($store, 'lock') || (!$isDevelopment && !$store instanceof \Illuminate\Cache\RedisStore)) {
            throw new \RuntimeException('Gift card settlement requires a shared lock.');
        }
        $lock = $store->lock('gift-card-settlement:' . $cartId, 300);
        if (!is_object($lock) || !method_exists($lock, 'get') || !method_exists($lock, 'release') || !$lock->get()) {
            throw new \RuntimeException('Gift card settlement is already in progress.');
        }
        try {
            $protected = $this->giftCards->protect($reservationId, 'protect:' . $reservationId);
            if (($protected['reservation']['id'] ?? null) !== $reservationId
                || ($protected['reservation']['cart_id'] ?? null) !== $cartId
                || ($protected['reservation']['amount_cents'] ?? null) !== ($gift['gift_card_cents'] ?? null)) {
                throw new \RuntimeException('Protected reservation does not match the checkout.');
            }

            $orderId = $this->orders->giftCardOrderIdForCart($cartId, $snapshotId);
            if ($orderId === null) {
                $customer = $payload['customer'] ?? null;
                if (!is_array($customer) || empty($customer['email']) || empty($customer['firstname']) || empty($customer['lastname'])) {
                    throw new \RuntimeException('Gift card checkout customer data is incomplete.');
                }
                $details = (object) $customer;
                $details->payment_module = $payload['payment_module'] ?? null;
                $details->create_account = (bool) ($payload['create_account'] ?? false);
                $details->newsletter = (bool) ($payload['newsletter'] ?? false);
                $this->orders->confirmSessionOrder(
                    $cartId, $payload['customer_id'] ?? null, $payload['guest_id'] ?? null,
                    $payload['carrier_id'] ?? null, $payload['coupon_code'] ?? null,
                    $customer['email'], $customer['firstname'], $customer['lastname'], $details,
                    ((int) ($payload['totals']['grand_total_cents'] ?? 0)) / 100,
                    'Gift card', $snapshotId, true,
                );
                $orderId = $this->orders->giftCardOrderIdForCart($cartId, $snapshotId);
                if ($orderId === null) {
                    throw new \RuntimeException('Gift card order is not available yet.');
                }
            }
            $this->snapshots->bindOrder($snapshotId, $orderId);
            $captured = $this->giftCards->capture(
                $reservationId, $orderId, 'capture:' . $reservationId, $this->orders->giftCardPendingStateId(),
            );
            if (($captured['reservation']['id'] ?? null) !== $reservationId
                || ($captured['reservation']['status'] ?? null) !== 'captured'
                || ($captured['reservation']['order_id'] ?? null) !== $orderId
                || ($captured['reservation']['amount_cents'] ?? null) !== ($gift['gift_card_cents'] ?? null)) {
                throw new \RuntimeException('Captured reservation does not match the order.');
            }
            $this->orders->settleGiftCardOrder($cartId, $snapshotId);
            $occurredAt = new \DateTimeImmutable($snapshot['verified_at'], new \DateTimeZone('UTC'));
            if ($this->ledger !== null) {
                $amount = (int) $gift['gift_card_cents'];
                $this->ledger->record(FinancialMovement::fromArray([
                    'type' => 'payment', 'status' => 'available',
                    'amount' => intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT),
                    'currency' => 'EUR', 'occurred_at' => $occurredAt, 'order_id' => $orderId,
                    'customer_id' => $payload['customer_id'] ?? null, 'provider' => 'gift_card',
                    'provider_transaction_id' => $reservationId, 'provider_event_id' => 'capture:' . $reservationId,
                    'source_event_type' => 'gift_card.capture', 'source_event_id' => $reservationId,
                ]), 'gift-card:capture:' . $reservationId);
            }
            if ($this->stripeConnect->enabled()) {
                $this->stripeConnect->enqueueTransferAllocation(
                    $cartId, $snapshot['payment_reference'], 'dz_cart_' . $cartId,
                    $occurredAt, $payload, $orderId,
                );
            }
            if ($this->sellerNotifications !== null) {
                $this->sellerNotifications->notifyFinalizedSalesForCart($cartId, $occurredAt);
            }
            $this->snapshots->markGiftCardAllocationQueued($snapshotId);
            return $orderId;
        } finally {
            $lock->release();
        }
    }
}
