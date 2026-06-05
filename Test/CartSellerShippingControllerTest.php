<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Http\Controller\CartController;
use PS\Webservice\Service\PS\Cart;
use PS\Webservice\Service\SellerShippingCalculator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CartSellerShippingControllerTest extends TestCase
{
    public function test_calculate_seller_shipping_returns_grouped_result(): void
    {
        $cartService = $this->getMockBuilder(Cart::class)->disableOriginalConstructor()->getMock();
        $controller = new CartController($cartService, new SellerShippingCalculator());

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'products' => [
                ['seller_id' => 1, 'price_wt' => 25.0, 'quantity' => 2],
                ['seller_id' => 2, 'price_wt' => 10.0, 'quantity' => 1],
            ],
            'seller_rules' => [
                ['seller_id' => 1, 'free_shipping_threshold' => 50.0, 'shipping_cost' => 9.0],
                ['seller_id' => 2, 'free_shipping_threshold' => 20.0, 'shipping_cost' => 4.0],
            ],
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $result = $controller->calculateSellerShipping($request, $response, []);

        $payload = json_decode((string) $result->getBody(), true);
        $this->assertSame(200, $result->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertCount(2, $payload['data']['shipping_by_seller']);
    }

    public function test_calculate_seller_shipping_requires_seller_id_on_each_product(): void
    {
        $cartService = $this->getMockBuilder(Cart::class)->disableOriginalConstructor()->getMock();
        $controller = new CartController($cartService, new SellerShippingCalculator());

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'products' => [
                ['price_wt' => 25.0, 'quantity' => 2],
            ],
            'seller_rules' => [],
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $result = $controller->calculateSellerShipping($request, $response, []);

        $payload = json_decode((string) $result->getBody(), true);
        $this->assertSame(400, $result->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertStringContainsString('seller_id', $payload['data']['error']);
    }
}
