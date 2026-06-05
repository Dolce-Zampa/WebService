<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\SellerShippingCalculator;

final class SellerShippingCalculatorTest extends TestCase
{
    public function test_calculates_shipping_by_seller_with_thresholds(): void
    {
        $calculator = new SellerShippingCalculator();

        $result = $calculator->calculate(
            [
                ['seller_id' => 1, 'price_wt' => 20.0, 'quantity' => 2],
                ['seller_id' => 2, 'price_wt' => 15.0, 'quantity' => 1],
                ['seller_id' => 2, 'price_wt' => 10.0, 'quantity' => 2],
            ],
            [
                ['seller_id' => 1, 'free_shipping_threshold' => 35.0, 'shipping_cost' => 7.5],
                ['seller_id' => 2, 'free_shipping_threshold' => 40.0, 'shipping_cost' => 5.0],
            ]
        );

        $this->assertSame(
            [
                [
                    'seller_id' => 1,
                    'products_total' => 40.0,
                    'free_shipping_threshold' => 35.0,
                    'shipping_cost' => 0.0,
                    'is_free_shipping' => true,
                ],
                [
                    'seller_id' => 2,
                    'products_total' => 35.0,
                    'free_shipping_threshold' => 40.0,
                    'shipping_cost' => 5.0,
                    'is_free_shipping' => false,
                ],
            ],
            $result
        );
    }

    public function test_ignores_products_without_valid_seller_id(): void
    {
        $calculator = new SellerShippingCalculator();

        $result = $calculator->calculate(
            [
                ['seller_id' => 0, 'price_wt' => 10.0, 'quantity' => 1],
                ['price_wt' => 20.0, 'quantity' => 1],
                ['seller_id' => 3, 'price_wt' => 30.0, 'quantity' => 1],
            ],
            [
                ['seller_id' => 3, 'free_shipping_threshold' => 100.0, 'shipping_cost' => 8.0],
                ['seller_id' => 4, 'free_shipping_threshold' => 50.0, 'shipping_cost' => 6.0],
            ]
        );

        $this->assertCount(1, $result);
        $this->assertSame(3, $result[0]['seller_id']);
        $this->assertSame(8.0, $result[0]['shipping_cost']);
    }
}
