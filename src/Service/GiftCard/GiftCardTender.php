<?php
declare(strict_types=1);

namespace PS\Webservice\Service\GiftCard;

/** Validated, code-free payment split returned by the gift card ledger. */
final class GiftCardTender
{
    private function __construct(
        public readonly string $reservationId,
        public readonly int $cartId,
        public readonly int $totalCents,
        public readonly int $giftCardCents,
        public readonly int $stripeCents,
        public readonly string $expiresAt,
    ) {}

    /** @param array<string, mixed> $response */
    public static function fromReservation(array $response, int $cartId, ?int $customerId, int $totalCents, int $now): self
    {
        $reservation = $response['reservation'] ?? null;
        $card = $response['card'] ?? null;
        if (!is_array($reservation) || !is_array($card) || $cartId < 1 || $totalCents < 1
            || !is_string($reservation['id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $reservation['id'])
            || !is_string($reservation['card_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $reservation['card_id'])
            || ($card['id'] ?? null) !== $reservation['card_id']
            || ($reservation['status'] ?? null) !== 'held' || ($card['status'] ?? null) !== 'active'
            || ($card['currency'] ?? null) !== 'EUR' || ($reservation['cart_id'] ?? null) !== $cartId
            || ($reservation['customer_id'] ?? null) !== $customerId
            || !is_int($reservation['amount_cents'] ?? null) || $reservation['amount_cents'] < 1
            || $reservation['amount_cents'] > $totalCents
            || !is_string($reservation['expires_at'] ?? null)) {
            throw new \RuntimeException('Gift card reservation does not match the checkout.');
        }
        $expiry = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $reservation['expires_at'], new \DateTimeZone('UTC'));
        if ($expiry === false || $expiry->format('Y-m-d\TH:i:s\Z') !== $reservation['expires_at'] || $expiry->getTimestamp() <= $now) {
            throw new \RuntimeException('Gift card reservation has expired or has an invalid expiry.');
        }
        $amount = $reservation['amount_cents'];
        return new self($reservation['id'], $cartId, $totalCents, $amount, $totalCents - $amount, $reservation['expires_at']);
    }

    /** @return array{reservation_id:string,gift_card_cents:int,stripe_cents:int,expires_at:string} */
    public function toArray(): array
    {
        return [
            'reservation_id' => $this->reservationId,
            'gift_card_cents' => $this->giftCardCents,
            'stripe_cents' => $this->stripeCents,
            'expires_at' => $this->expiresAt,
        ];
    }
}
