<?php
declare(strict_types=1);

use PS\Webservice\Http\Controller\CartController;
use PS\Webservice\Service\PS\Cart;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CartCouponControllerTest extends TestCase
{
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

        $controller = new CartController($cartService);

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

        $controller = new CartController($cartService);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([]);
        $request->method('getQueryParams')->willReturn([]);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->validateCoupon($request, $response, ['code' => 'SAVE10', 'cartId' => 4]);

        $this->assertSame(400, $result->getStatusCode());
    }

    public function test_validate_coupon_returns_service_payload(): void
    {
        $cartService = $this->getMockBuilder(Cart::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['validateCoupon'])
            ->getMock();

        $cartService->expects($this->once())
            ->method('validateCoupon')
            ->with('SAVE10', 5, 'cust', null)
            ->willReturn(['valid' => true]);

        $controller = new CartController($cartService);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['customer_id' => 'cust']);
        $request->method('getQueryParams')->willReturn(['customer_id' => 'cust']);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->validateCoupon($request, $response, ['code' => 'SAVE10', 'cartId' => 5]);

        $this->assertSame(200, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame(['valid' => true], $body['data']);
    }
}
