<?php
declare(strict_types=1);

use PS\Webservice\Domain\Entities\CarrierEntity;
use PS\Webservice\Domain\Entities\CartEntity;
use PS\Webservice\Http\Controller\OrderController;
use PS\Webservice\Service\PS\Cart;
use PS\Webservice\Service\PS\Order;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class OrderSecurityTest extends TestCase
{
    // -------------------------------------------------------- confirmOrder polling

    public function test_confirm_order_returns_400_when_cart_id_is_invalid(): void
    {
        $orderService = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrderByCartId'])
            ->getMock();

        $orderService->expects($this->never())->method('getOrderByCartId');

        $controller = new OrderController($orderService);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([]);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->confirmOrder($request, $response, []);

        $this->assertSame(400, $result->getStatusCode());
    }

    public function test_confirm_order_returns_202_while_waiting_for_order(): void
    {
        $orderService = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrderByCartId'])
            ->getMock();

        $orderService->expects($this->once())
            ->method('getOrderByCartId')
            ->with(42)
            ->willReturn(null);

        $controller = new OrderController($orderService);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['id_cart' => 42]);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->confirmOrder($request, $response, []);

        $this->assertSame(202, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertFalse($body['success']);
        $this->assertSame('pending', $body['status']);
    }

    public function test_confirm_order_returns_success_true_only_for_payment_accepted_state(): void
    {
        $orderService = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrderByCartId'])
            ->getMock();

        $orderEntity = $this->createMock(OrderEntity::class);
        $orderEntity->method('toArray')->willReturn([
            'id' => 100,
            'id_cart' => 42,
            'current_state' => 2,
        ]);

        $orderService->expects($this->once())
            ->method('getOrderByCartId')
            ->with(42)
            ->willReturn($orderEntity);

        $controller = new OrderController($orderService);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['id_cart' => 42]);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->confirmOrder($request, $response, []);

        $this->assertSame(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertTrue($body['success']);
    }

    public function test_confirm_order_returns_success_false_for_non_accepted_state(): void
    {
        $orderService = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrderByCartId'])
            ->getMock();

        $orderEntity = $this->createMock(OrderEntity::class);
        $orderEntity->method('toArray')->willReturn([
            'id' => 100,
            'id_cart' => 42,
            'current_state' => 3,
        ]);

        $orderService->expects($this->once())
            ->method('getOrderByCartId')
            ->with(42)
            ->willReturn($orderEntity);

        $controller = new OrderController($orderService);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['id_cart' => 42]);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->confirmOrder($request, $response, []);

        $this->assertSame(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertFalse($body['success']);
    }

    public function test_confirm_order_returns_500_when_order_state_is_missing(): void
    {
        $orderService = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrderByCartId'])
            ->getMock();

        $orderEntity = $this->createMock(OrderEntity::class);
        $orderEntity->method('toArray')->willReturn([
            'id' => 100,
            'id_cart' => 42,
        ]);

        $orderService->expects($this->once())
            ->method('getOrderByCartId')
            ->with(42)
            ->willReturn($orderEntity);

        $controller = new OrderController($orderService);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['id_cart' => 42]);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->confirmOrder($request, $response, []);

        $this->assertSame(500, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertFalse($body['success']);
    }

    // -------------------------------------------------------- createOrder ownership

    public function test_create_order_returns_403_when_no_owner_id_provided(): void
    {
        $orderService = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCartFromId'])
            ->getMock();

        $orderService->expects($this->never())->method('getCartFromId');

        $controller = new OrderController($orderService);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'id_cart' => 10,
            'id_carrier' => 2,
        ]);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->createOrder($request, $response, []);

        $this->assertSame(403, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    public function test_create_order_returns_404_when_cart_not_found_for_owner(): void
    {
        $orderService = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCartFromId'])
            ->getMock();

        $orderService->expects($this->once())
            ->method('getCartFromId')
            ->with(10, 5, null)
            ->willReturn(null);

        $controller = new OrderController($orderService);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'id_cart' => 10,
            'id_customer' => 5,
            'id_carrier' => 2,
        ]);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->createOrder($request, $response, []);

        $this->assertSame(404, $result->getStatusCode());
    }

    // -------------------------------------------------------- server-side prices

    public function test_create_order_uses_server_side_product_prices(): void
    {
        $serviceMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCartFromId', 'getCarrierDetail', 'getProductPriceById'])
            ->getMock();

        $cartServiceStub = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->getMock();

        $cartEntity = CartEntity::create([
            'id' => 10,
            'products' => [
                ['id_product' => 7, 'quantity' => 2, 'name' => 'Croquette', 'price_wt' => '999.99'],
            ],
        ], $cartServiceStub);

        $serviceMock->expects($this->once())
            ->method('getCartFromId')
            ->with(10, 5, null)
            ->willReturn($cartEntity);

        $carrierEntity = CarrierEntity::create([
            'id' => 2,
            'name' => [['id' => '1', 'value' => 'Express']],
            'delay' => [['id' => '1', 'value' => '1-2 days']],
        ], $cartServiceStub);

        $serviceMock->expects($this->once())
            ->method('getCarrierDetail')
            ->with(2)
            ->willReturn($carrierEntity);

        // The key assertion: server-side price is fetched per product, not from cart
        $serviceMock->expects($this->once())
            ->method('getProductPriceById')
            ->with(7)
            ->willReturn(12.20);  // server-side price, NOT the '999.99' from the cart

        // Stub out Stripe by throwing a known exception so we don't need a real API key
        $_ENV['STRIPE_API_KEY'] = 'sk_test_stub';
        $_ENV['STRIPE_SUCCESS_URL'] = 'https://example.com/ok';
        $_ENV['STRIPE_CANCEL_URL'] = 'https://example.com/cancel';

        $controller = new OrderController($serviceMock);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'id_cart' => 10,
            'id_customer' => 5,
            'id_carrier' => 2,
            'paymentMethod' => 'stripe',
        ]);
        $response = $this->createMock(ResponseInterface::class);

        // The controller will attempt to create a Stripe session which will fail with
        // an invalid API key — that's fine; we only care that getProductPriceById was
        // called (asserted above) and that the response is not a 404/403.
        $result = $controller->createOrder($request, $response, []);

        $this->assertNotSame(403, $result->getStatusCode());
        $this->assertNotSame(404, $result->getStatusCode());
    }

    // -------------------------------------------------------- initiatePayment ownership

    public function test_initiate_payment_returns_403_when_no_owner_id_provided(): void
    {
        $orderService = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCartFromId'])
            ->getMock();

        $orderService->expects($this->never())->method('getCartFromId');

        $controller = new OrderController($orderService);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'id_cart' => 10,
        ]);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->initiatePayment($request, $response, []);

        $this->assertSame(403, $result->getStatusCode());
    }

    public function test_initiate_payment_returns_400_when_no_cart_id(): void
    {
        $orderService = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCartFromId'])
            ->getMock();

        $orderService->expects($this->never())->method('getCartFromId');

        $controller = new OrderController($orderService);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'id_customer' => 5,
            // no id_cart
        ]);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->initiatePayment($request, $response, []);

        $this->assertSame(400, $result->getStatusCode());
    }

    public function test_initiate_payment_returns_404_when_cart_not_found_for_owner(): void
    {
        $orderService = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCartFromId'])
            ->getMock();

        $orderService->expects($this->once())
            ->method('getCartFromId')
            ->with(10, 5, null)
            ->willReturn(null);

        $controller = new OrderController($orderService);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'id_cart' => 10,
            'id_customer' => 5,
        ]);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->initiatePayment($request, $response, []);

        $this->assertSame(404, $result->getStatusCode());
    }
}
