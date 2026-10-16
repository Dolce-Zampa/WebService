<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Service\PS\Order;

final class StripeConnectOrderSessionTest extends TestCase
{
    public function test_connect_checkout_sets_an_internal_transfer_group_only(): void
    {
        $order = $this->createMock(Order::class);
        $session = OrderSession::create([
            'cart_id' => 77,
            'id_carrier' => 2,
            'customer' => CustomerEntity::create(['email' => 'buyer@example.test', 'firstname' => 'Buyer', 'lastname' => 'Test'], $order),
        ], $order);

        $session->enableStripeConnect('dz_cart_77');

        $payload = $session->toArray();
        self::assertSame('true', $payload['metadata']['stripe_connect']);
        self::assertSame('dz_cart_77', $payload['metadata']['transfer_group']);
        self::assertSame(['transfer_group' => 'dz_cart_77'], $payload['payment_intent_data']);
        self::assertArrayNotHasKey('seller_id', $payload['metadata']);
    }

    public function test_rejects_an_invalid_transfer_group(): void
    {
        $order = $this->createMock(Order::class);
        $session = OrderSession::create([
            'cart_id' => 77,
            'id_carrier' => 2,
            'customer' => CustomerEntity::create(['email' => 'buyer@example.test', 'firstname' => 'Buyer', 'lastname' => 'Test'], $order),
        ], $order);

        $this->expectException(InvalidArgumentException::class);
        $session->enableStripeConnect('group with spaces');
    }
}
