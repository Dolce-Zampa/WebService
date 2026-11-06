<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\GiftCard\GiftCardTender;
use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Service\PS\Order;

final class GiftCardTenderTest extends TestCase
{
    private function reservation(int $amount = 5000): array
    {
        return ['reservation' => [
            'id' => str_repeat('a', 32), 'card_id' => str_repeat('b', 32), 'cart_id' => 42, 'customer_id' => 5,
            'amount_cents' => $amount, 'status' => 'held', 'expires_at' => '2030-01-01T00:00:00Z',
        ], 'card' => ['id' => str_repeat('b', 32), 'status' => 'active', 'currency' => 'EUR']];
    }

    public function testPartialAndFullCoverageAreRepresentedInCentsWithoutTheCode(): void
    {
        $partial = GiftCardTender::fromReservation($this->reservation(), 42, 5, 7500, 1);
        self::assertSame(5000, $partial->giftCardCents);
        self::assertSame(2500, $partial->stripeCents);
        self::assertArrayNotHasKey('code', $partial->toArray());

        $full = GiftCardTender::fromReservation($this->reservation(3000), 42, 5, 3000, 1);
        self::assertSame(0, $full->stripeCents);
    }

    public function testRejectsReservationForAnotherCustomer(): void
    {
        $this->expectException(RuntimeException::class);
        GiftCardTender::fromReservation($this->reservation(), 42, 6, 7500, 1);
    }

    public function testRejectsAnExpiredReservation(): void
    {
        $this->expectException(RuntimeException::class);
        GiftCardTender::fromReservation($this->reservation(), 42, 5, 7500, 1893456000);
    }

    public function testACompletelyCoveredOrderCannotCreateAStripePayment(): void
    {
        $order = $this->createMock(Order::class);
        $session = OrderSession::create([
            'cart_id' => 42, 'id_customer' => 5, 'id_carrier' => 3,
            'customer' => CustomerEntity::create(['email' => 'buyer@example.test', 'firstname' => 'Buyer', 'lastname' => 'Example'], $order),
        ], $order);
        $session->addCartLineItem(['id_product' => 10, 'name' => 'Collare', 'price_wt' => '30.00', 'quantity' => 1]);
        $session->attachGiftCardTender(GiftCardTender::fromReservation($this->reservation(3000), 42, 5, 3000, 1));

        $this->expectException(InvalidArgumentException::class);
        $session->toStripePaymentArray();
    }
}
