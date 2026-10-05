<?php
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Entities\CarrierEntity;
use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Domain\Object\Discount;
use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Service\PS\Order;

final class CouponFlowTest extends TestCase
{
    private function session(Order $service): OrderSession
    {
        return OrderSession::create([
            'cart_id' => 10, 'id_customer' => 5, 'id_guest' => null, 'id_carrier' => 2,
            'customer' => CustomerEntity::create(['email' => 'test@example.com'], $service),
        ], $service);
    }

    public static function totals(): array
    {
        return [
            'fixed discount' => [6.0, 96.0, 10.0],
            'restricted percentage discount' => [6.0, 101.0, 5.0],
            'combined coupons' => [6.0, 81.0, 25.0],
            'free shipping' => [0.0, 100.0, 0.0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('totals')]
    public function test_payment_uses_server_total(float $shipping, float $grand, float $discount): void
    {
        $service = $this->getMockBuilder(Order::class)->disableOriginalConstructor()
            ->onlyMethods(['createCouponCode', 'findExistingStripeCoupon'])->getMock();
        $service->expects($this->never())->method('findExistingStripeCoupon');
        $service->expects($discount > 0 ? $this->once() : $this->never())->method('createCouponCode')
            ->willReturnCallback(function (Discount $actual) use ($discount): string {
                $this->assertSame($discount, $actual->amount_off);
                $this->assertSame('amount', $actual->type);
                $this->assertSame('SAVE10', $actual->code);
                return 'stripe_generated_id';
            });
        $session = $this->session($service);
        $session->addCartLineItem(['id_product' => 1, 'name' => 'Product', 'price_wt' => 50, 'quantity' => 2]);
        $session->applyCouponCart([
            'id' => 10, 'currency_iso' => 'EUR',
            'totals' => ['shipping_tax_incl' => $shipping, 'grand_total_tax_incl' => $grand],
            'cart_rules' => [['code' => 'SAVE10'], ['code' => 'OTHER']],
        ]);
        $this->assertSame('SAVE10', $session->metadata['coupon_code']);
        $this->assertSame($grand, $session->payableTotal());
        $this->assertSame($grand, $session->total() - $discount);
        if ($discount > 0) {
            $this->assertSame([['coupon' => 'stripe_generated_id']], $session->toArray()['discounts']);
        }
    }

    public function test_inconsistent_totals_are_rejected_before_creating_a_stripe_coupon(): void
    {
        $service = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods(['createCouponCode'])->getMock();
        $service->expects($this->never())->method('createCouponCode');
        $session = $this->session($service);
        $session->addCartLineItem(['id_product' => 1, 'name' => 'Product', 'price_wt' => 10, 'quantity' => 1]);
        $this->expectException(InvalidArgumentException::class);
        $session->applyCouponCart(['id' => 10, 'currency_iso' => 'EUR', 'cart_rules' => [['code' => 'SAVE10']],
            'totals' => ['shipping_tax_incl' => 0, 'grand_total_tax_incl' => 20]]);
    }

    public function test_cart_shipping_total_is_used_for_checkout_line_item(): void
    {
        $session = $this->session($this->createMock(Order::class));
        $session->addCartLineItem(['id_product' => 1, 'name' => 'Product', 'price_wt' => 10, 'quantity' => 1]);
        $session->addCartShipping(['totals' => ['shipping_tax_incl' => 4.55]]);

        $this->assertSame(1455, $session->getLineItems()[1]['price_data']['unit_amount']);
    }

    public function test_stripe_shipping_rate_uses_the_selected_carrier_mapping(): void
    {
        $previousRates = $_ENV['STRIPE_SHIPPING_RATE_IDS'] ?? null;
        $_ENV['STRIPE_SHIPPING_RATE_IDS'] = '{"2":"shr_testCarrier2","3":"shr_testCarrier3"}';

        try {
            $service = $this->createMock(Order::class);
            $session = $this->session($service);
            $carrier = CarrierEntity::create(['id' => 2], $service);
            $session->addCarrier($carrier);

            $this->assertSame([['shipping_rate' => 'shr_testCarrier2']], $session->toArray()['shipping_options']);
        } finally {
            if ($previousRates === null) {
                unset($_ENV['STRIPE_SHIPPING_RATE_IDS']);
            } else {
                $_ENV['STRIPE_SHIPPING_RATE_IDS'] = $previousRates;
            }
        }
    }

    public function test_stripe_shipping_rate_rejects_a_carrier_that_is_not_selected(): void
    {
        $service = $this->createMock(Order::class);
        $session = $this->session($service);
        $carrier = CarrierEntity::create(['id' => 3], $service);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must match the selected carrier');
        $session->addCarrier($carrier);
    }
}
