<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Service\PS\Order;

final class OrderSessionMetadataTest extends TestCase
{
    public function test_virtual_checkout_accepts_zero_carrier_but_physical_checkout_rejects_it(): void
    {
        $service = $this->createMock(Order::class);
        $customer = CustomerEntity::create(['email' => 'customer@example.com', 'firstname' => 'Test', 'lastname' => 'Customer'], $service);
        $data = ['cart_id' => 42, 'id_carrier' => 0, 'is_virtual' => true, 'customer' => $customer];
        $session = OrderSession::create($data, $service);
        self::assertSame('0', $session->metadata['id_carrier']);
        self::assertSame('true', $session->metadata['is_virtual']);
        $data['is_virtual'] = false;
        $this->expectException(\InvalidArgumentException::class);
        OrderSession::create($data, $service);
    }

    public function test_metadata_is_string_only_and_excludes_customer_email(): void
    {
        $service = $this->createMock(Order::class);
        $customer = CustomerEntity::create([
            'email' => 'customer@example.com',
            'firstname' => 'Test',
            'lastname' => 'Customer',
            'newsletter' => false,
        ], $service);
        $session = OrderSession::create([
            'cart_id' => 42,
            'id_customer' => null,
            'id_guest' => 7,
            'id_carrier' => 15,
            'payment_module' => 'webserviceapi',
            'create_account' => 'false',
            'recovery_attempt' => true,
            'customer' => $customer,
        ], $service);
        $metadata = $session->metadata;

        self::assertSame('42', $metadata['cart_id']);
        self::assertSame('', $metadata['id_customer']);
        self::assertSame('7', $metadata['id_guest']);
        self::assertSame('webserviceapi', $metadata['payment_module']);
        self::assertSame('false', $metadata['create_account']);
        self::assertSame('false', $metadata['newsletter']);
        self::assertSame('true', $metadata['recovery_attempt']);
        self::assertArrayNotHasKey('customer_email', $metadata);
    }
}
