<?php
declare(strict_types=1);

use DolzeZampa\WS\Service\HttpServiceInterface;
use DolzeZampa\WS\Service\PS\Cart;
use PHPUnit\Framework\TestCase;

final class CartServicePriceTest extends TestCase
{
    public function test_get_product_price_by_id_returns_price_with_22_percent_vat(): void
    {
        $httpService = $this->createMock(HttpServiceInterface::class);

        $httpService->expects($this->once())
            ->method('setUrl')
            ->with($this->stringContains('/products?'));

        $httpService->expects($this->once())
            ->method('invoke')
            ->with('GET')
            ->willReturnSelf();

        $httpService->expects($this->once())
            ->method('toArray')
            ->willReturn([
                'products' => [
                    ['id' => '7', 'price' => '10.000000'],
                ],
            ]);

        $cart = new Cart($httpService);
        $price = $cart->getProductPriceById(7);

        // 10.00 * 1.22 = 12.20
        $this->assertSame(12.20, $price);
    }

    public function test_get_product_price_throws_when_product_not_found(): void
    {
        $httpService = $this->createMock(HttpServiceInterface::class);
        $httpService->method('setUrl')->willReturnSelf();
        $httpService->method('invoke')->willReturnSelf();
        $httpService->method('toArray')->willReturn(['products' => []]);

        $cart = new Cart($httpService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Product #99 not found/');

        $cart->getProductPriceById(99);
    }
}
