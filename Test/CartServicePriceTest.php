<?php
declare(strict_types=1);

use PS\Webservice\Service\HttpServiceInterface;
use PS\Webservice\Service\PS\Cart;
use PHPUnit\Framework\TestCase;

final class CartServicePriceTest extends TestCase
{
    public function test_get_product_price_by_id_uses_the_tax_inclusive_prestashop_price(): void
    {
        $httpService = $this->createMock(HttpServiceInterface::class);

        $httpService->expects($this->once())
            ->method('setUrl')
            ->with($this->callback(function (string $url): bool {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                return ($query['price']['original_price'] ?? []) === ['use_tax' => '1', 'use_reduction' => '1'];
            }));

        $httpService->expects($this->once())
            ->method('invoke')
            ->with('GET')
            ->willReturnSelf();

        $httpService->expects($this->once())
            ->method('toArray')
            ->willReturn([
                'products' => [
                    ['id' => '7', 'price' => '10.000000', 'original_price' => '12.200000'],
                ],
            ]);

        $cart = new Cart($httpService);
        $price = $cart->getProductPriceById(7);

        // PrestaShop already included tax; do not apply it again.
        $this->assertSame(12.20, $price);
    }

    public function test_tax_exempt_product_remains_at_37_euros(): void
    {
        $http = $this->createMock(HttpServiceInterface::class);
        $http->method('invoke')->willReturnSelf();
        $http->method('toArray')->willReturn([
            'products' => [['id' => 245, 'price' => '37.000000', 'original_price' => '37.000000']],
        ]);
        $this->assertSame(37.0, (new Cart($http))->getProductPriceById(245));
    }

    public function test_uses_the_discounted_price_calculated_by_prestashop(): void
    {
        $http = $this->createMock(HttpServiceInterface::class);
        $http->method('invoke')->willReturnSelf();
        $http->method('toArray')->willReturn([
            'products' => [['id' => 245, 'price' => '37.000000', 'original_price' => '25.000000']],
        ]);
        $this->assertSame(25.0, (new Cart($http))->getProductPriceById(245));
    }

    public function test_missing_calculated_price_does_not_fall_back_to_an_untaxed_price(): void
    {
        $http = $this->createMock(HttpServiceInterface::class);
        $http->method('invoke')->willReturnSelf();
        $http->method('toArray')->willReturn(['products' => [['id' => 245, 'price' => '37.000000']]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing calculated price for product #245');
        (new Cart($http))->getProductPriceById(245);
    }

    public function test_get_product_price_throws_when_product_not_found(): void
    {
        $httpService = $this->createMock(HttpServiceInterface::class);
        $httpService->method('setUrl')->willReturnSelf();
        $httpService->method('invoke')->willReturnSelf();
        $httpService->method('toArray')->willReturn(['products' => []]);

        $cart = new Cart($httpService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Product #99 not found in catalog/');

        $cart->getProductPriceById(99);
    }
}
