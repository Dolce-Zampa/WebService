<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Entities\CartEntity;
use PS\Webservice\Http\Controller\CartController;
use PS\Webservice\Repositories\PrestashopRepository;
use PS\Webservice\Service\PS\Cart;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CartOwnershipTest extends TestCase
{
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

    public function test_get_cart_returns_401_when_request_is_not_authenticated(): void
    {
        $cartService = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCartFromId'])
            ->getMock();
        $cartService->expects($this->never())->method('getCartFromId');

        $controller = new CartController($cartService, $this->createRepositoryMock());

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->with('user_id')->willReturn(null);
        $request->method('getHeaderLine')->with('Authorization')->willReturn('');
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->getCart($request, $response, ['cartId' => 42]);

        $this->assertSame(401, $result->getStatusCode());
    }

    public function test_get_cart_uses_authenticated_customer_instead_of_query_parameter(): void
    {
        $cartService = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCartFromId'])
            ->getMock();

        $cartService->expects($this->once())
            ->method('getCartFromId')
            ->with(42, 5, null)
            ->willReturn(null);

        $controller = new CartController($cartService, $this->createRepositoryMock());

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->with('user_id')->willReturn('customer-sub');
        $request->method('getQueryParams')->willReturn(['id_customer' => 99]);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->getCart($request, $response, ['cartId' => 42]);

        $this->assertSame(404, $result->getStatusCode());
    }

    public function test_get_cart_list_returns_403_when_path_customer_differs_from_authenticated_customer(): void
    {
        $cartService = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCartListFromUserId'])
            ->getMock();
        $cartService->expects($this->never())->method('getCartListFromUserId');

        $controller = new CartController($cartService, $this->createRepositoryMock(5));

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->with('user_id')->willReturn('customer-sub');
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->getCartList($request, $response, ['customerId' => 8]);

        $this->assertSame(403, $result->getStatusCode());
    }

    public function test_get_cart_list_uses_authenticated_customer_when_path_matches(): void
    {
        $cartService = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCartListFromUserId'])
            ->getMock();

        $stubCartService = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->getMock();

        $cartEntity = CartEntity::create(['id' => 42, 'products' => []], $stubCartService);

        $cartService->expects($this->once())
            ->method('getCartListFromUserId')
            ->with('5')
            ->willReturn($cartEntity);

        $controller = new CartController($cartService, $this->createRepositoryMock(5));

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->with('user_id')->willReturn('customer-sub');
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->getCartList($request, $response, ['customerId' => 5]);

        $this->assertSame(200, $result->getStatusCode());
    }

    public function test_get_cart_returns_200_for_verified_owner(): void
    {
        $cartService = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCartFromId'])
            ->getMock();

        $stubCartService = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->getMock();

        $cartEntity = CartEntity::create(
            ['id' => 42, 'products' => []],
            $stubCartService
        );

        $cartService->expects($this->once())
            ->method('getCartFromId')
            ->with(42, 5, null)
            ->willReturn($cartEntity);

        $controller = new CartController($cartService, $this->createRepositoryMock());

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->with('user_id')->willReturn('customer-sub');
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->getCart($request, $response, ['cartId' => 42]);

        $this->assertSame(200, $result->getStatusCode());
    }

    public function test_get_cart_still_supports_guest_ownership_context(): void
    {
        $cartService = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCartFromId'])
            ->getMock();

        $stubCartService = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->getMock();

        $cartEntity = CartEntity::create(
            ['id' => 42, 'products' => []],
            $stubCartService
        );

        $cartService->expects($this->once())
            ->method('getCartFromId')
            ->with(42, null, 'guest-42')
            ->willReturn($cartEntity);

        $controller = new CartController($cartService, $this->createRepositoryMock());

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->with('user_id')->willReturn(null);
        $request->method('getHeaderLine')->with('Authorization')->willReturn('');
        $request->method('getQueryParams')->willReturn(['id_guest' => 'guest-42']);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->getCart($request, $response, ['cartId' => 42]);

        $this->assertSame(200, $result->getStatusCode());
    }


    public function test_validate_coupon_uses_authenticated_customer_and_cart_ownership(): void
    {
        $cartService = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCartFromId', 'validateCoupon'])
            ->getMock();

        $stubCartService = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->getMock();

        $cartEntity = CartEntity::create(['id' => 4, 'products' => []], $stubCartService);

        $cartService->expects($this->once())
            ->method('getCartFromId')
            ->with('4', 5, null)
            ->willReturn($cartEntity);

        $cartService->expects($this->once())
            ->method('validateCoupon')
            ->with('SAVE10', '4', '5', null)
            ->willReturn(['valid' => true]);

        $controller = new CartController($cartService, $this->createRepositoryMock());

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->with('user_id')->willReturn('customer-sub');
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->validateCoupon($request, $response, ['code' => 'SAVE10', 'cartId' => 4]);

        $this->assertSame(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame(['valid' => true], $body['data']);
    }
}
