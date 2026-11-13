<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../resources/prestashop-modules/webserviceapi/classes/MlabMarketplaceShipping.php';

final class MarketplaceShippingTest extends TestCase
{
    public function test_each_artisan_uses_the_selected_carrier_quote_and_threshold(): void
    {
        $lines = [
            ['id_manufacturer' => 1, 'total_wt' => 50, 'is_virtual' => 0],
            ['id_manufacturer' => 1, 'total_wt' => 49, 'is_virtual' => 0],
            ['id_manufacturer' => 2, 'total_wt' => 10, 'is_virtual' => 0],
            ['id_manufacturer' => 3, 'total_wt' => 10, 'is_virtual' => 0],
            ['id_manufacturer' => 4, 'total_wt' => 2, 'is_virtual' => 1],
        ];
        $quoted = [];
        $price = MlabMarketplaceShipping::calculate($lines, 99, static function ($seller, $products) use (&$quoted) {
            $quoted[] = $seller;
            return $seller === 2 ? 3.90 : 5.20;
        });
        self::assertSame(9.10, $price);
        self::assertSame([2, 3], $quoted);
        self::assertFalse(MlabMarketplaceShipping::calculate($lines, 99, static fn () => false));
    }

    public function test_threshold_can_be_configured_without_a_second_rule(): void
    {
        putenv('MARKETPLACE_FREE_SHIPPING_THRESHOLD=150');
        try {
            self::assertSame(150.0, MlabMarketplaceShipping::threshold());
            self::assertSame(3.9, MlabMarketplaceShipping::calculate(
                [['id_manufacturer' => 1, 'total_wt' => 99, 'is_virtual' => 0]],
                MlabMarketplaceShipping::threshold(), static fn () => 3.9
            ));
        } finally {
            putenv('MARKETPLACE_FREE_SHIPPING_THRESHOLD');
        }
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_prestashop_hook_prices_each_artisan_with_the_chosen_carrier(): void
    {
        require __DIR__ . '/stubs/marketplace-shipping-runtime.php';
        require __DIR__ . '/../resources/prestashop-modules/webserviceapi/webserviceapi.php';
        $module = (new ReflectionClass('webserviceapi'))->newInstanceWithoutConstructor();
        $cart = new MarketplaceShippingCartStub($module);
        $cost = 1.0;
        $params = ['cart' => $cart, 'id_carrier' => 20, 'use_tax' => true, 'default_country' => null,
            'product_list' => $cart->getProducts(), 'id_zone' => null, 'shippingCost' => &$cost];
        $module->hookActionCartGetPackageShippingCost($params);
        self::assertSame(7.8, $cost);
        self::assertSame([20, 20], $cart->quotedCarriers);
        self::assertSame(2, $cart->disabledGlobalThresholds);

        $params['id_carrier'] = 19;
        $module->hookActionCartGetPackageShippingCost($params);
        self::assertSame(12.2, $cost);
        $cost = false;
        $module->hookActionCartGetPackageShippingCost($params);
        self::assertFalse($cost);
    }
}
