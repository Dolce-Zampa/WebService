<?php
declare(strict_types=1);

use DolzeZampa\WS\Domain\Entities\CartEntity;
use DolzeZampa\WS\Http\Controller\CartController;
use DolzeZampa\WS\Service\PS\Cart;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CartOwnershipTest extends TestCase
{
    // ------------------------------------------------------------------ getCart

    public function test_get_cart_returns_403_when_no_owner_id_provided(): void
    {
        $cartService = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCartFromId'])
            ->getMock();

        // getCartFromId should never be called when ownership info is missing
        $cartService->expects($this->never())->method('getCartFromId');

        $controller = new CartController($cartService);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);

        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->getCart($request, $response, ['cartId' => '42']);

        $this->assertSame(403, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    public function test_get_cart_returns_404_when_cart_does_not_belong_to_customer(): void
    {
        $cartService = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCartFromId'])
            ->getMock();

        // PS service returns null → cart not found for this owner
        $cartService->expects($this->once())
            ->method('getCartFromId')
            ->with(42, 99, null)
            ->willReturn(null);

        $controller = new CartController($cartService);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['customer_id' => '99']);

        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->getCart($request, $response, ['cartId' => '42']);

        $this->assertSame(404, $result->getStatusCode());
    }

    public function test_get_cart_returns_404_when_cart_does_not_belong_to_guest(): void
    {
        $cartService = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCartFromId'])
            ->getMock();

        $cartService->expects($this->once())
            ->method('getCartFromId')
            ->with(42, null, 7)
            ->willReturn(null);

        $controller = new CartController($cartService);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['guest_id' => '7']);

        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->getCart($request, $response, ['cartId' => '42']);

        $this->assertSame(404, $result->getStatusCode());
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

        $controller = new CartController($cartService);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['customer_id' => '5']);

        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->getCart($request, $response, ['cartId' => '42']);

        $this->assertSame(200, $result->getStatusCode());
    }
}
