<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class CartMetadataSerializationTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_metadata_is_batched_and_serialization_preserves_cart_lines(): void
    {
        require __DIR__ . '/stubs/prestashop-cart-runtime.php';
        require __DIR__ . '/../resources/prestashop-modules/webserviceapi/classes/MlabFactoryApiHelper.php';

        $cart = new \Cart();
        $line = ['id_product' => 234, 'id_product_attribute' => 0, 'id_customization' => 0,
            'name' => 'Collare', 'reference' => 'COL', 'cart_quantity' => 2,
            'price_wt' => 20, 'total_wt' => 40];
        $cart->products = [$line, array_replace($line, ['id_product_attribute' => 7]),
            array_replace($line, ['id_product' => 235])];
        $db = \Db::getInstance();
        $db->metadata = [
            ['id_product' => '234', 'id_manufacturer' => '8', 'manufacturer_name' => 'Artigiano',
                'id_supplier' => '9', 'link_rewrite' => 'collare'],
            ['id_product' => '235', 'id_manufacturer' => '0', 'manufacturer_name' => null,
                'id_supplier' => '0', 'link_rewrite' => null],
        ];

        $serialized = \MlabFactoryApiHelper::serializeCart($cart);
        self::assertCount(3, $serialized['products']);
        foreach (array_slice($serialized['products'], 0, 2) as $product) {
            self::assertSame(8, $product['id_manufacturer']);
            self::assertSame('Artigiano', $product['manufacturer_name']);
            self::assertSame(9, $product['id_supplier']);
            self::assertSame('collare', $product['link_rewrite']);
            self::assertSame(20.0, $product['price_wt']);
            self::assertSame(40.0, $product['total_wt']);
            self::assertSame(2, $product['quantity']);
            self::assertNull($product['notes']);
        }
        self::assertSame(7, $serialized['products'][1]['id_product_attribute']);
        self::assertSame(999, $serialized['products'][1]['id_image']);
        self::assertSame(0, $serialized['products'][2]['id_manufacturer']);
        self::assertSame('', $serialized['products'][2]['manufacturer_name']);
        self::assertSame('', $serialized['products'][2]['link_rewrite']);
        self::assertSame(6.1, $serialized['totals']['shipping_tax_incl']);
        $queries = array_values(array_filter($db->queries, fn ($sql) => str_contains($sql, 'manufacturer')));
        self::assertCount(1, $queries);
        self::assertStringContainsString('IN (234,235)', $queries[0]);
        self::assertStringContainsString('pl.id_lang = 2', $queries[0]);
        self::assertStringContainsString('pl.id_shop = 3', $queries[0]);

        $db->queries = [];
        $cart->products = [];
        self::assertSame([], \MlabFactoryApiHelper::serializeCart($cart)['products']);
        self::assertSame([], $db->queries);

        $cart->products = [$line];
        $db->metadata = false;
        $this->expectException(\MlabFactoryApiException::class);
        $this->expectExceptionMessage('Unable to retrieve cart product metadata.');
        \MlabFactoryApiHelper::serializeCart($cart);
    }
}
