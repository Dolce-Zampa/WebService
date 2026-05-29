<?php
declare(strict_types=1);

use PS\Webservice\Domain\Entities\ProductEntity;
use PS\Webservice\Domain\Object\WebserviceConfig;
use PS\Webservice\Service\HttpServiceInterface;
use PS\Webservice\Service\PS\Product;
use PHPUnit\Framework\TestCase;

final class ProductServiceTest extends TestCase
{
    private WebserviceConfig $config;

    protected function setUp(): void
    {
        $this->config = new WebserviceConfig('test-api-key', 'shop.example.com');
    }

    private function mockHttp(): HttpServiceInterface
    {
        $http = $this->createMock(HttpServiceInterface::class);
        $http->method('getConfig')->willReturn($this->config);
        return $http;
    }

    private function sampleProductData(): array
    {
        return [
            'id' => '5',
            'name' => 'Cibo per cani',
            'description' => 'Ottimo per il tuo cane.',
            'price' => '10.000000',
            'original_price' => '10.000000',
            'url' => null,
            'associations' => [],
        ];
    }

    // ------------------------------------------------------------------ countProducts

    public function test_count_products_returns_correct_count(): void
    {
        $http = $this->mockHttp();
        $http->expects($this->once())->method('setUrl')->with($this->stringContains('/products?'));
        $http->expects($this->once())->method('invoke')->with('GET')->willReturnSelf();
        $http->expects($this->once())->method('failed')->willReturn(false);
        $http->expects($this->once())->method('toArray')->willReturn([
            'products' => [['id' => '1'], ['id' => '2'], ['id' => '3']],
        ]);

        $service = new Product($http);
        $count = $service->countProducts();

        $this->assertSame(3, $count);
    }

    public function test_count_products_returns_zero_when_no_products(): void
    {
        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willReturnSelf();
        $http->method('failed')->willReturn(false);
        $http->method('toArray')->willReturn([]);

        $service = new Product($http);
        $count = $service->countProducts();

        $this->assertSame(0, $count);
    }

    public function test_count_products_throws_on_failure(): void
    {
        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willReturnSelf();
        $http->method('failed')->willReturn(true);
        $http->method('getBody')->willReturn('Service Unavailable');

        $service = new Product($http);

        $this->expectException(\PS\Webservice\Service\PS\PrestashopConnectorException::class);
        $service->countProducts();
    }

    // ------------------------------------------------------------------ getProductById

    public function test_get_product_by_id_returns_entity_on_success(): void
    {
        $http = $this->mockHttp();
        $http->expects($this->once())->method('setUrl')->with($this->stringContains('/products/5?'));
        $http->expects($this->once())->method('invoke')->with('GET')->willReturnSelf();
        $http->expects($this->once())->method('failed')->willReturn(false);
        $http->expects($this->once())->method('toArray')->willReturn([
            'products' => [$this->sampleProductData()],
        ]);

        $service = new Product($http);
        $result = $service->getProductById(5);

        $this->assertInstanceOf(ProductEntity::class, $result);
        $this->assertSame(5, $result->getId());
    }

    public function test_get_product_by_id_returns_null_on_404(): void
    {
        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willReturnSelf();
        $http->method('failed')->willReturn(true);
        $http->method('getHttpCode')->willReturn(404);

        $service = new Product($http);
        $result = $service->getProductById(9999);

        $this->assertNull($result);
    }

    // ------------------------------------------------------------------ findProductIdBySlug

    public function test_find_product_id_by_slug_returns_id_when_found(): void
    {
        $http = $this->mockHttp();
        $http->expects($this->once())->method('setUrl')->with('/catalog?by_slug=cibo-cani');
        $http->expects($this->once())->method('invoke')->with('GET')->willReturnSelf();
        $http->expects($this->once())->method('failed')->willReturn(false);
        $http->expects($this->once())->method('toArray')->willReturn([
            'data' => ['id_product' => '5'],
        ]);

        $service = new Product($http);
        $result = $service->findProductIdBySlug('cibo-cani');

        $this->assertSame(5, $result);
    }

    public function test_find_product_id_by_slug_returns_null_when_not_found(): void
    {
        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willReturnSelf();
        $http->method('failed')->willReturn(false);
        $http->method('toArray')->willReturn(['data' => []]);

        $service = new Product($http);
        $result = $service->findProductIdBySlug('non-existent-slug');

        $this->assertNull($result);
    }

    public function test_find_product_id_by_slug_throws_on_failure(): void
    {
        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willReturnSelf();
        $http->method('failed')->willReturn(true);
        $http->method('getBody')->willReturn('Internal Server Error');

        $service = new Product($http);

        $this->expectException(\PS\Webservice\Service\PS\PrestashopConnectorException::class);
        $service->findProductIdBySlug('any-slug');
    }

    // ------------------------------------------------------------------ searchProducts

    public function test_search_products_returns_collection_with_entities(): void
    {
        $http = $this->mockHttp();
        $http->expects($this->once())->method('setUrl')->with('/search?query=cane&language=1&display=full');
        $http->expects($this->once())->method('invoke')->with('GET')->willReturnSelf();
        $http->expects($this->once())->method('failed')->willReturn(false);
        $http->expects($this->once())->method('toArray')->willReturn([
            'products' => [$this->sampleProductData()],
        ]);

        $service = new Product($http);
        $result = $service->searchProducts('cane');

        $this->assertCount(1, $result);
        $this->assertInstanceOf(ProductEntity::class, $result->first());
    }

    public function test_search_products_returns_empty_collection_when_no_results(): void
    {
        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willReturnSelf();
        $http->method('failed')->willReturn(false);
        $http->method('toArray')->willReturn(['products' => []]);

        $service = new Product($http);
        $result = $service->searchProducts('xyz123nonexistent');

        $this->assertCount(0, $result);
    }
}
