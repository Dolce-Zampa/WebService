<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Http\Controller\CartController;
use PS\Webservice\Http\Controller\OrderController;
use PS\Webservice\Http\Middleware\SellerAuthorizationMiddleware;
use PS\Webservice\Repositories\PrestashopRepository;
use PS\Webservice\Service\HttpServiceInterface;
use PS\Webservice\Service\Payments\PaymentGatewayInterface;
use PS\Webservice\Service\PS\Cart;
use PS\Webservice\Service\PS\Customer;
use PS\Webservice\Service\PS\Order;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class ApiAuthorizationRegressionTest extends TestCase
{
    private function repository(): PrestashopRepository
    {
        $repository = $this->createMock(PrestashopRepository::class);
        $repository->method('findUserIdFromSub')->with('customer-sub')->willReturn(5);
        return $repository;
    }

    public static function cartMutations(): array
    {
        return [['updateCart'], ['deleteCart'], ['validateCoupon']];
    }

    #[DataProvider('cartMutations')]
    public function test_foreign_cart_cannot_be_modified(string $method): void
    {
        $service = $this->createMock(Cart::class);
        $service->expects($this->once())->method('getCartFromId')->with(42, 5, null)->willReturn(null);
        foreach (['updateCart', 'deleteCart', 'validateCoupon'] as $mutation) {
            $service->expects($this->never())->method($mutation);
        }
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/cart/42')
            ->withAttribute('user_id', 'customer-sub')
            ->withParsedBody(['id_customer' => 999, 'customerId' => 999, 'id_guest' => 'spoofed']);
        $result = (new CartController($service, $this->repository()))->$method($request, new Response(), ['cartId' => 42, 'code' => 'SAVE']);
        self::assertSame(404, $result->getStatusCode());
    }

    public function test_order_detail_is_scoped_to_authenticated_customer(): void
    {
        $service = $this->createMock(Order::class);
        $service->expects($this->once())->method('orderDetails')->with('42', '5')->willReturn(null);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/order/42')
            ->withAttribute('user_id', 'customer-sub')->withQueryParams(['id_customer' => 999]);
        $controller = new OrderController($service, $this->createMock(PaymentGatewayInterface::class), $this->repository());
        self::assertSame(404, $controller->getOrder($request, new Response(), ['orderId' => 42])->getStatusCode());
    }

    public function test_foreign_customer_order_history_is_denied(): void
    {
        $service = $this->createMock(Order::class);
        $service->expects($this->never())->method('getOrderListFromUserId');
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/order/history/999')->withAttribute('user_id', 'customer-sub');
        $controller = new OrderController($service, $this->createMock(PaymentGatewayInterface::class), $this->repository());
        self::assertSame(403, $controller->orderHistory($request, new Response(), ['customerId' => 999])->getStatusCode());
    }

    public static function customerUpdates(): array
    {
        return [['updateAccount', '/account?noc_cache=1'], ['updateAddresses', '/addresses?noc_cache=1']];
    }

    #[DataProvider('customerUpdates')]
    public function test_nested_customer_cannot_override_authorized_identity(string $method, string $url): void
    {
        $http = $this->createMock(HttpServiceInterface::class);
        $http->expects($this->once())->method('setUrl')->with($url);
        $http->expects($this->once())->method('invoke')->with('PUT', ['id_customer' => 5, 'firstname' => 'Mario'])->willReturn($http);
        (new Customer($http))->$method(5, ['id_customer' => 5, 'customer' => ['id_customer' => 999, 'firstname' => 'Mario']]);
    }

    public static function sellerIdentities(): array
    {
        return [[null, 401], ['customer-sub', 403], ['seller-sub', 200]];
    }

    #[DataProvider('sellerIdentities')]
    public function test_seller_role_is_required(?string $sub, int $status): void
    {
        Manager::schema()->dropIfExists('manufacturer');
        Manager::schema()->create('manufacturer', function ($table): void {
            $table->integer('id_manufacturer')->primary();
            $table->string('sub');
        });
        Manager::table('manufacturer')->insert(['id_manufacturer' => 7, 'sub' => 'seller-sub']);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/seller/me')->withAttribute('user_id', $sub);
        $handler = $this->createMock(RequestHandlerInterface::class);
        if ($status === 200) {
            $handler->expects($this->once())->method('handle')->with($this->callback(fn ($r) => $r->getAttribute('authenticated_manufacturer_id') === 7))->willReturn(new Response());
        } else {
            $handler->expects($this->never())->method('handle');
        }
        self::assertSame($status, (new SellerAuthorizationMiddleware())->process($request, $handler)->getStatusCode());
    }
}
