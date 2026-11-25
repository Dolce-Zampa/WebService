<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Illuminate\Support\Facades\Facade;
use Psr\Log\NullLogger;
use PS\Webservice\Domain\Entities\CartEntity;
use PS\Webservice\Http\Controller\OrderController;
use PS\Webservice\Service\Payments\PaymentGatewayInterface;
use PS\Webservice\Service\PS\Order;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ProtectionPreviewControllerTest extends TestCase
{
    private mixed $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(['log' => new NullLogger()]);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        parent::tearDown();
    }

    public function testPreviewUsesTheOwnedServerCartInsteadOfClientAmounts(): void
    {
        $order = $this->createMock(Order::class);
        $cart = CartEntity::create(['id' => 42, 'currency_iso' => 'EUR',
            'products' => [['id_product' => 10, 'quantity' => 1]],
            'totals' => ['grand_total_tax_incl' => 86.90, 'shipping_tax_incl' => 6.90]], $order);
        $order->expects(self::once())->method('getCartFromId')->with(42, null, 8)->willReturn($cart);
        $payments = $this->createMock(PaymentGatewayInterface::class);
        $payments->expects(self::never())->method('createPaymentSession');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['id_cart' => 42, 'id_guest' => 8, 'price_cents' => 1]);
        $response = (new OrderController($order, $payments))->previewPurchaseProtection($request, $this->createMock(ResponseInterface::class), []);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $data = json_decode((string) $response->getBody(), true)['data'];
        self::assertSame(400, $data['price_cents']);
        self::assertTrue($data['is_estimate']);
    }

    public function testRegisteredCustomerMustBeAuthenticated(): void
    {
        $order = $this->createMock(Order::class);
        $order->expects(self::never())->method('getCartFromId');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['id_cart' => 42, 'id_customer' => 5]);
        $response = (new OrderController($order, $this->createMock(PaymentGatewayInterface::class)))
            ->previewPurchaseProtection($request, $this->createMock(ResponseInterface::class), []);
        self::assertSame(404, $response->getStatusCode());
    }

    public function testSelectedProtectionCannotReachPaymentBeforeTheServiceIsImplemented(): void
    {
        $order = $this->createMock(Order::class);
        $order->expects(self::never())->method('getCartFromId');
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::never())->method('createPaymentSession');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['purchase_protection' => true]);
        $response = (new OrderController($order, $gateway))->createOrder($request, $this->createMock(ResponseInterface::class), []);
        self::assertSame(503, $response->getStatusCode());
    }
}
