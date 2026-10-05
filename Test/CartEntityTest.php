<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Entities\CartEntity;
use PS\Webservice\Service\PS\PrestashopServiceInterface;

final class CartEntityTest extends TestCase
{
    public function test_generate_payload_preserves_products_by_default_and_omits_missing_addresses(): void
    {
        $cart = CartEntity::create([
            'id' => 42,
            'id_address_delivery' => 0,
            'id_address_invoice' => null,
            'products' => [['id_product' => 7]],
        ], $this->createMock(PrestashopServiceInterface::class));

        $payload = $cart->generatePayload()->toArray();

        self::assertFalse($payload['replace_products']);
        self::assertArrayNotHasKey('id_address_delivery', $payload);
        self::assertArrayNotHasKey('id_address_invoice', $payload);
    }

    public function test_generate_payload_honors_replacement_and_address_ids(): void
    {
        $cart = CartEntity::create([
            'id' => 42,
            'replace_products' => true,
            'id_address_delivery' => 34,
            'id_address_invoice' => 35,
            'products' => [['id_product' => 7]],
        ], $this->createMock(PrestashopServiceInterface::class));

        $payload = $cart->generatePayload()->toArray();

        self::assertTrue($payload['replace_products']);
        self::assertSame(34, $payload['id_address_delivery']);
        self::assertSame(35, $payload['id_address_invoice']);
    }

    public function test_generate_payload_rejects_a_non_boolean_replacement_option(): void
    {
        $cart = CartEntity::create([
            'id' => 42,
            'replace_products' => 'true',
            'products' => [['id_product' => 7]],
        ], $this->createMock(PrestashopServiceInterface::class));

        $this->expectException(InvalidArgumentException::class);
        $cart->generatePayload();
    }
}
