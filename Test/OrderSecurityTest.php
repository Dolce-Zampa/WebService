<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Entities\CarrierEntity;
use PS\Webservice\Domain\Entities\CartEntity;
use PS\Webservice\Domain\Entities\OrderEntity;
use PS\Webservice\Domain\Entities\ProductEntity;
use PS\Webservice\Http\Controller\OrderController;
use PS\Webservice\Repositories\PrestashopRepository;
use PS\Webservice\Service\Payments\PaymentGatewayInterface;
use PS\Webservice\Service\PS\Cart;
use PS\Webservice\Service\PS\Order;
use PS\Webservice\Service\PS\Product;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class OrderSecurityTest extends TestCase
{
    private $cache;
    private $taggedCache;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();

        $this->cache = $this->createMock(\Illuminate\Cache\Repository::class);
        $this->taggedCache = $this->createMock(\Illuminate\Cache\TaggedCache::class);

        $this->cache
            ->method('tags')
            ->willReturn($this->taggedCache);

        $this->taggedCache
            ->method('tags')
            ->willReturn($this->taggedCache);

        \Illuminate\Support\Facades\Facade::setFacadeApplication([
            'cache' => $this->cache,
            'log' => $this->createMock(\Psr\Log\LoggerInterface::class),
            'queue-service' => $this->createMock(\PS\Webservice\Service\RedisQueue::class)
        ]);
        
        // create table webserviceapi_configurator
        \Illuminate\Database\Capsule\Manager::schema()->dropIfExists('webserviceapi_configurator');
        \Illuminate\Database\Capsule\Manager::schema()->create('webserviceapi_configurator', function ($table) {
            $table->integer('id_product')->primary();
            $table->string('json');
            $table->integer('active');
            $table->integer('id_shop');
        });
    }

    private function createRepositoryMock(int $customerId = 5): PrestashopRepository
    {
        $repository = $this->getMockBuilder(PrestashopRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findUserIdFromSub'])
            ->getMock();
        $repository->method('findUserIdFromSub')
            ->with('customer-sub')
            ->willReturn($customerId);

        return $repository;
    }

    private function createAuthenticatedRequest(array $payload): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($payload);
        $request->method('getAttribute')->with('user_id')->willReturn('customer-sub');

        return $request;
    }

    private function createOrderController(Order $orderService, ?PaymentGatewayInterface $stripeService = null): OrderController
    {
        $stripe = $stripeService ?? $this->createMock(PaymentGatewayInterface::class);
        return new OrderController($orderService, $stripe, $this->createRepositoryMock());
    }

    // -------------------------------------------------------- confirmOrder polling

    public function test_confirm_order_returns_400_when_cart_id_is_invalid(): void
    {
        $orderService = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrderByCartId'])
            ->getMock();

        $orderService->expects($this->never())->method('getOrderByCartId');

        $controller = $this->createOrderController($orderService);

        $request = $this->createAuthenticatedRequest([]);
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
            ->with(42, 5, null)
            ->willReturn(null);

        $controller = $this->createOrderController($orderService);

        $request = $this->createAuthenticatedRequest(['id_cart' => 42]);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->confirmOrder($request, $response, []);

        $this->assertSame(202, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertFalse($body['data']['success']);
        $this->assertSame('pending', $body['data']['status']);
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
            ->with(42, 5, null)
            ->willReturn($orderEntity);

        $controller = $this->createOrderController($orderService);

        $request = $this->createAuthenticatedRequest(['id_cart' => 42]);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->confirmOrder($request, $response, []);

        $this->assertSame(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertTrue($body['data']['success']);
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
            ->with(42, 5, null)
            ->willReturn($orderEntity);

        $controller = $this->createOrderController($orderService);

        $request = $this->createAuthenticatedRequest(['id_cart' => 42]);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->confirmOrder($request, $response, []);

        $this->assertSame(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertFalse($body['data']['success']);
    }

    public function test_confirm_order_supports_guest_ownership_context(): void
    {
        $orderService = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrderByCartId'])
            ->getMock();

        $orderService->expects($this->once())
            ->method('getOrderByCartId')
            ->with(42, null, 'guest-42')
            ->willReturn(null);

        $controller = $this->createOrderController($orderService);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'id_cart' => 42,
            'id_guest' => 'guest-42',
        ]);
        $request->method('getAttribute')->with('user_id')->willReturn(null);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->confirmOrder($request, $response, []);

        $this->assertSame(202, $result->getStatusCode());
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
            ->with(42, 5, null)
            ->willReturn($orderEntity);

        $controller = $this->createOrderController($orderService);

        $request = $this->createAuthenticatedRequest(['id_cart' => 42]);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->confirmOrder($request, $response, []);

        $this->assertSame(500, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertFalse($body['success']);
    }

    // -------------------------------------------------------- createOrder ownership

    public function test_create_order_returns_401_when_request_is_not_authenticated(): void
    {
        $orderService = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCartFromId'])
            ->getMock();

        $orderService->expects($this->never())->method('getCartFromId');

        $controller = $this->createOrderController($orderService);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'id_cart' => 10,
            'id_carrier' => 2,
        ]);
        $request->method('getAttribute')->with('user_id')->willReturn(null);
        $request->method('getHeaderLine')->with('Authorization')->willReturn('');
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->createOrder($request, $response, []);

        $this->assertSame(401, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertArrayHasKey('error', $body['data']);
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

        $controller = $this->createOrderController($orderService);

        $request = $this->createAuthenticatedRequest([
            'id_cart' => 10,
            'id_customer' => 999,
            'id_carrier' => 2,
        ]);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->createOrder($request, $response, []);

        $this->assertSame(404, $result->getStatusCode());
    }

    public function test_create_order_still_supports_guest_ownership_context(): void
    {
        $orderService = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCartFromId'])
            ->getMock();

        $orderService->expects($this->once())
            ->method('getCartFromId')
            ->with(10, null, 'guest-7')
            ->willReturn(null);

        $controller = $this->createOrderController($orderService);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'id_cart' => 10,
            'id_guest' => 'guest-7',
            'id_carrier' => 2,
        ]);
        $request->method('getAttribute')->with('user_id')->willReturn(null);
        $request->method('getHeaderLine')->with('Authorization')->willReturn('');
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->createOrder($request, $response, []);

        $this->assertSame(404, $result->getStatusCode());
    }


    // -------------------------------------------------------- server-side prices

    public static function serverCartShippingCases(): array
    {
        return [
            'variants above threshold' => ['119.99', '29.90', 2, false],
            'below threshold' => ['30.00', '38.99', 2, true],
            'exactly 99' => ['30.00', '39.00', 2, false],
            'above threshold by one cent' => ['30.00', '39.01', 2, false],
            'quantity reaches threshold' => ['33.00', '0.00', 3, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('serverCartShippingCases')]
    public function test_create_order_uses_server_cart_variant_prices_and_ignores_frontend_prices(
        string $firstPrice, string $secondPrice, int $firstQuantity, bool $expectedShipping
    ): void
    {
        $this->taggedCache
            ->method('has')
            ->willReturn(true);

        $this->taggedCache
            ->method('get')
            ->willReturn(true);

        $serviceMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCartFromId', 'getCarrierDetail', 'getProductPriceById', 'getProductById'])
            ->getMock();

        $cartServiceStub = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->getMock();

        $cartEntity = CartEntity::create([
            'id' => 10,
            'products' => [
                ['id_product' => 7, 'id_product_attribute' => 665, 'quantity' => $firstQuantity, 'name' => 'Croquette', 'attributes' => 'Formato: 12 kg', 'reference' => 'CROQ-12', 'price_wt' => $firstPrice],
                ['id_product' => 7, 'id_product_attribute' => 666, 'quantity' => 1, 'name' => 'Croquette', 'attributes' => 'Formato: 3 kg', 'reference' => 'CROQ-3', 'price_wt' => $secondPrice],
            ],
        ], $cartServiceStub);

        $serviceMock->expects($this->once())
            ->method('getCartFromId')
            ->with('10', '5', null)
            ->willReturn($cartEntity);

        $carrierEntity = CarrierEntity::create([
            'id' => 2,
            'name' => [['id' => 1, 'value' => 'Express']],
            'price_with_tax' => '6.10',
            'delay' => [['id' => 1, 'value' => '1-2 days']],
        ], $cartServiceStub);

        $serviceMock->expects($this->once())
            ->method('getCarrierDetail')
            ->with(2)
            ->willReturn($carrierEntity);

        // The catalog's base price must not replace the selected variant's server cart price.
        $serviceMock->expects($this->never())->method('getProductPriceById');
        $serviceMock->expects($this->never())->method('getProductById');

        $stripe = $this->createMock(PaymentGatewayInterface::class);
        $stripe->expects($this->once())
            ->method('createPaymentSession')
            ->willReturnCallback(function (\PS\Webservice\Domain\Object\OrderSession $session) use ($firstPrice, $secondPrice, $firstQuantity, $expectedShipping): string {
                $lines = array_values(array_filter($session->getLineItems(),
                    static fn (array $line): bool => isset($line['price_data']['product_data']['metadata']['id_product'])));
                $this->assertCount(2, $lines);
                foreach ([[665, (int) round((float) $firstPrice * 100), $firstQuantity, 'Formato: 12 kg', 'CROQ-12'], [666, (int) round((float) $secondPrice * 100), 1, 'Formato: 3 kg', 'CROQ-3']] as $index => $expected) {
                    [$variantId, $amount, $quantity, $attributes, $reference] = $expected;
                    $line = $lines[$index];
                    $product = $line['price_data']['product_data'];
                    $this->assertSame('7', $product['metadata']['id_product']);
                    $this->assertSame((string) $variantId, $product['metadata']['id_product_attribute']);
                    $this->assertSame($amount, $line['price_data']['unit_amount']);
                    $this->assertSame($quantity, $line['quantity']);
                    $this->assertSame('Croquette - ' . $attributes, $product['name']);
                    $this->assertSame($reference, $product['description']);
                }
                $shippingLines = array_values(array_filter($session->getLineItems(),
                    static fn (array $line): bool => !isset($line['price_data']['product_data']['metadata']['id_product'])));
                $this->assertCount($expectedShipping ? 1 : 0, $shippingLines);
                if ($expectedShipping) {
                    $this->assertSame(610, $shippingLines[0]['price_data']['unit_amount']);
                    $this->assertSame(1, $shippingLines[0]['quantity']);
                }
                return 'https://example.com/checkout';
            });
        $controller = $this->createOrderController($serviceMock, $stripe);

        $request = $this->createAuthenticatedRequest([
            'id' => 1,
            'id_cart' => 10,
            'id_customer' => 999,
            'id_carrier' => 2,
            'paymentMethod' => 'stripe',
            'reference' => 'REF123',
            'current_state' => 2,
            'date_add' => '2026-01-01',
            // Deliberately forged frontend amounts and variants must be ignored.
            'products' => [['id_product' => 7, 'id_product_attribute' => 999, 'quantity' => 1, 'price_wt' => '0.01']],
            'total_paid_tax_incl' => 0.01,
            'total_paid_tax_excl' => 20.00,
            'delivery_address' => ['address1' => 'Via Main'],
            'invoice_address' => ['address1' => 'Via Main'],
            'customer' => [
                'id_customer' => 5,
                'firstname' => 'Mario',
                'lastname' => 'Rossi',
                'email' => 'mario@example.com',
                'phone' => '123456789',
                'delivery_address' => ['address1' => 'Via Main'],
                'invoice_address' => ['address1' => 'Via Main'],
            ],
        ]);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->createOrder($request, $response, []);

        $this->assertSame(201, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('https://example.com/checkout', $body['data']['payment_url']);
    }

    // -------------------------------------------------------- initiatePayment ownership

    public function test_initiate_payment_returns_403_when_no_owner_id_provided(): void
    {
        $orderService = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCartFromId'])
            ->getMock();

        $orderService->expects($this->never())->method('getCartFromId');

        $controller = $this->createOrderController($orderService);

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

        $controller = $this->createOrderController($orderService);

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

        $controller = $this->createOrderController($orderService);

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
