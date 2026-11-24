<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PurchaseProtection;

use Illuminate\Database\Capsule\Manager;
use PS\Webservice\Service\Payments\PaymentGatewayInterface;

/** Retires existing sessions before a caller mutates a cart's service product. */
final class CartPaymentGuard
{
    public function __construct(private readonly Manager $db, private readonly PaymentGatewayInterface $payments) {}

    /** The caller must hold a distributed cart lock until the replacement checkout exists. */
    public function retireUnpaidSessions(int $cartId): void
    {
        if ($cartId < 1) throw new \InvalidArgumentException('Invalid cart ID.');
        $records = $this->db->table('stripe_checkout_snapshots')->where('cart_id', $cartId)
            ->select(['checkout_session_id', 'order_id', 'gift_card_reservation_id',
                'gift_card_payment_verified_at', 'gift_card_released_at'])->get();
        $sessions = [];
        foreach ($records as $record) {
            if (!empty($record->order_id) || !empty($record->gift_card_payment_verified_at)) {
                throw new \RuntimeException('The cart has an existing paid checkout.');
            }
            if (!empty($record->gift_card_reservation_id) && empty($record->gift_card_released_at)) {
                throw new \RuntimeException('The cart has an active gift card reservation.');
            }
            $session = $record->checkout_session_id ?? null;
            if (!is_string($session) || !preg_match('/^cs_[A-Za-z0-9_]{1,188}$/D', $session)) {
                // A failed response can leave a payable Stripe session without
                // its ID locally. Do not guess whether it exists or expired.
                throw new \RuntimeException('An earlier checkout requires reconciliation.');
            }
            $sessions[$session] = true;
        }
        foreach (array_keys($sessions) as $session) {
            // This checks Stripe again after expiration and fails if payment won the race.
            $this->payments->expireUnpaidSession($session);
        }
    }
}
