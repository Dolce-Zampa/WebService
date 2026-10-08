<?php
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Entities\CartEntity;
use PS\Webservice\Domain\Object\Discount;
use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Http\Controller\OrderController;
use PS\Webservice\Service\Payments\PaymentGatewayInterface;
use PS\Webservice\Service\PS\Order;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

final class CouponCheckoutControllerTest extends TestCase
{
    public function test_forged_discount_is_replaced_with_prestashop_totals(): void
    {
        $cache = $this->createMock(\Illuminate\Cache\Repository::class);
        $tagged = $this->createMock(\Illuminate\Cache\TaggedCache::class);
        $cache->method('tags')->willReturn($tagged); $tagged->method('tags')->willReturn($tagged);
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication(['cache' => $cache]);
        $service = $this->getMockBuilder(Order::class)->disableOriginalConstructor()
            ->onlyMethods(['getCartFromId', 'prepareCouponCart', 'createCouponCode', 'getCarrierDetail'])->getMock();
        $data = ['id' => 10, 'currency_iso' => 'EUR', 'products' => [
            ['id_product' => 1, 'name' => 'Product', 'price_wt' => 100, 'quantity' => 1]],
            'cart_rules' => [['code' => 'SAVE10']],
            'totals' => ['shipping_tax_incl' => 6, 'grand_total_tax_incl' => 96]];
        $cart = CartEntity::create($data, $service);
        $service->method('getCartFromId')->willReturn($cart);
        $service->expects($this->once())->method('prepareCouponCart')->with(10, 5, null, 2, ['SAVE10'])->willReturn($cart);
        $service->expects($this->never())->method('getCarrierDetail');
        $service->expects($this->once())->method('createCouponCode')->willReturnCallback(function (Discount $discount) {
            $this->assertSame(10.0, $discount->amount_off);
            $this->assertSame('amount', $discount->type);
            return 'stripe_coupon';
        });
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects($this->once())->method('createPaymentSession')->willReturnCallback(function (OrderSession $session) {
            $this->assertSame(96.0, $session->payableTotal());
            $this->assertSame('SAVE10', $session->metadata['coupon_code']);
            return 'https://example.com/pay';
        });
        $payload = ['id' => 1, 'id_cart' => 10, 'id_customer' => 5, 'id_guest' => null, 'id_carrier' => 2, 'id_lang' => 1,
            'reference' => '', 'current_state' => 0, 'date_add' => '', 'total_paid_tax_incl' => 1, 'total_paid_tax_excl' => 1,
            'customer' => ['email' => 'test@example.com', 'firstname' => 'Test', 'lastname' => 'Customer'],
            'delivery_address' => ['id_country' => 10], 'invoice_address' => ['id_country' => 10],
            'cart_rules' => [['code' => 'SAVE10', 'reduction_percent' => 99, 'reduction_amount' => 999]]];
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($payload);
        $controller = new OrderController($service, $gateway);
        $response = $controller->createOrder($request, $this->createMock(ResponseInterface::class), []);
        $this->assertSame(201, $response->getStatusCode());
    }
}
