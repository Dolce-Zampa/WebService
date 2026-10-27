<?php
declare(strict_types=1);

use PS\Webservice\Http\Controller\CartController;
use PS\Webservice\Repositories\PrestashopRepository;
use PS\Webservice\Service\PS\Cart;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CartCouponControllerTest extends TestCase
{
    private function createRepositoryMock(): PrestashopRepository
    {
        return $this->getMockBuilder(PrestashopRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findUserIdFromSub'])
            ->getMock();
    }

    public function test_get_coupon_detail_returns_404_when_not_found(): void
    {
        $cartService = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCouponDetail'])
            ->getMock();

        $cartService->expects($this->once())
            ->method('getCouponDetail')
            ->with('UNKNOWN')
            ->willReturn(null);

        $controller = new CartController($cartService, $this->createRepositoryMock());

        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $result = $controller->getCouponDetail($request, $response, ['code' => 'UNKNOWN']);

        $this->assertSame(404, $result->getStatusCode());
    }

    public function test_validate_coupon_requires_owner_identifier(): void
    {
        $cartService = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['validateCoupon'])
            ->getMock();

        $cartService->expects($this->never())->method('validateCoupon');

        $controller = new CartController($cartService, $this->createRepositoryMock());

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([]);
        $request->method('getQueryParams')->willReturn([]);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->validateCoupon($request, $response, ['code' => 'SAVE10', 'cartId' => 4]);

        $this->assertSame(401, $result->getStatusCode());
    }

    public function test_validate_coupon_returns_service_payload(): void
    {
        $cartService = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['validateCoupon', 'getCartFromId'])
            ->getMock();

        $cartService->expects($this->once())
            ->method('validateCoupon')
            ->with('SAVE10', '5', '5', null)
            ->willReturn(['valid' => true]);

        $cartService->expects($this->once())->method('getCartFromId')->with('5', 5, null)
            ->willReturn(\PS\Webservice\Domain\Entities\CartEntity::create(['id' => 5, 'products' => []], $cartService));
        $repository = $this->createRepositoryMock();
        $repository->method('findUserIdFromSub')->with('customer-sub')->willReturn(5);
        $controller = new CartController($cartService, $repository);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->with('user_id')->willReturn('customer-sub');
        $request->method('getParsedBody')->willReturn(['customer_id' => 'cust']);
        $request->method('getQueryParams')->willReturn(['customer_id' => 'cust']);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->validateCoupon($request, $response, ['code' => 'SAVE10', 'cartId' => 5]);

        $this->assertSame(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame(['valid' => true], $body['data']);
    }
}
