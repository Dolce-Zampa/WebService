<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Entities\CartEntity;
use PS\Webservice\Service\PS\PrestashopServiceInterface;

final class CartCustomizationTest extends TestCase
{
    public static function customizationValues(): array
    {
        return [
            'text stays text' => ['Luna'],
            'unicode and punctuation' => ['È Luna & Milo 🐾'],
            'filename keeps extension' => ['550e8400-e29b-41d4-a716-446655440000.png'],
            'S3 reference stays intact' => ['customization/42/550e8400-e29b-41d4-a716-446655440000.png'],
            'text resembling a path stays intact' => ['Luna/Milo'],
        ];
    }

    #[DataProvider('customizationValues')]
    public function test_customization_value_reaches_prestashop_payload_unchanged(string $value): void
    {
        $cart = $this->createCart([['id' => 12, 'value' => $value]]);
        $expected = [['id_customization_field' => 12, 'value' => $value]];

        self::assertSame($expected, $cart->toArray()['products'][0]['customizations']);
        self::assertSame($expected, $cart->generatePayload()->toArray()['products'][0]['customizations']);
    }

    public function test_text_and_file_keep_their_respective_field_ids(): void
    {
        $cart = $this->createCart([
            ['id' => 12, 'value' => 'Luna'],
            ['id' => 13, 'value' => 'uploaded-image.png'],
        ]);

        $payload = $cart->generatePayload()->toArray();
        self::assertSame([
            ['id_customization_field' => 12, 'value' => 'Luna'],
            ['id_customization_field' => 13, 'value' => 'uploaded-image.png'],
        ], $payload['products'][0]['customizations']);
        self::assertSame(42, $payload['products'][0]['id_product']);
        self::assertSame(7, $payload['products'][0]['id_product_attribute']);
        self::assertSame(2, $payload['products'][0]['quantity']);
    }

    public function test_product_without_customizations_has_an_empty_customization_list(): void
    {
        $cart = CartEntity::create([
            'id' => 99,
            'products' => [['id_product' => 42, 'quantity' => 1]],
        ], $this->createMock(PrestashopServiceInterface::class));

        self::assertSame([], $cart->generatePayload()->toArray()['products'][0]['customizations']);
    }

    private function createCart(array $customizations): CartEntity
    {
        return CartEntity::create([
            'id' => 99,
            'products' => [[
                'id_product' => 42,
                'id_product_attribute' => 7,
                'quantity' => 2,
            ]],
            'customizations' => $customizations,
        ], $this->createMock(PrestashopServiceInterface::class));
    }
}
