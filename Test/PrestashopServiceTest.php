<?php
declare(strict_types=1);

use PS\Webservice\Domain\Entities\CategoryEntity;
use PS\Webservice\Domain\Entities\OptionEntity;
use PS\Webservice\Domain\Entities\StockAvailableEntity;
use PS\Webservice\Service\HttpServiceInterface;
use PS\Webservice\Service\PS\PrestashopService;
use PHPUnit\Framework\TestCase;

final class PrestashopServiceTest extends TestCase
{
    // ------------------------------------------------------------------ helpers

    private function mockHttp(): HttpServiceInterface
    {
        return $this->createMock(HttpServiceInterface::class);
    }

    // ------------------------------------------------------------------ getSpecificationsImage

    public function test_get_specifications_image_returns_entity_on_success(): void
    {
        $http = $this->mockHttp();
        $http->expects($this->once())->method('setUrl')->with('/images/products/1/10/product_main');
        $http->expects($this->once())->method('invoke')->with('GET')->willReturnSelf();
        $http->expects($this->once())->method('toArray')->willReturn([
            'id' => '10',
            'url' => 'https://cdn.example.com/img/1/10.jpg',
        ]);

        $service = new PrestashopService($http);
        $result = $service->getSpecificationsImage(1, 10, \PS\Webservice\Domain\Enums\ImageTail::ORIGINAL);

        $this->assertInstanceOf(\PS\Webservice\Domain\Entities\ImageEntity::class, $result);
    }

    public function test_get_specifications_image_returns_null_on_exception(): void
    {
        $http = $this->mockHttp();
        $http->expects($this->once())->method('setUrl');
        $http->expects($this->once())
            ->method('invoke')
            ->willThrowException(new \RuntimeException('connection refused'));

        $service = new PrestashopService($http);
        $result = $service->getSpecificationsImage(1, 1, \PS\Webservice\Domain\Enums\ImageTail::ORIGINAL);

        $this->assertNull($result);
    }

    // ------------------------------------------------------------------ getSpecificationsOption

    public function test_get_specifications_option_returns_entity_on_success(): void
    {
        $http = $this->mockHttp();
        $http->expects($this->once())->method('setUrl')->with('/product_option_values/5?display=full');
        $http->expects($this->once())->method('invoke')->with('GET')->willReturnSelf();
        $http->expects($this->once())->method('failed')->willReturn(false);
        $http->expects($this->once())->method('toArray')->willReturn([
            'product_option_values' => [
                [
                    'id' => '5',
                    'id_attribute_group' => '2',
                    'color' => '',
                    'position' => '1',
                    'name' => [['id' => '1', 'value' => 'Rosso']],
                ],
            ],
        ]);

        $service = new PrestashopService($http);
        $result = $service->getSpecificationsOption(5);

        $this->assertInstanceOf(OptionEntity::class, $result);
        $this->assertSame(5, $result->getId());
    }

    public function test_get_specifications_option_throws_on_failure(): void
    {
        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willReturnSelf();
        $http->method('failed')->willReturn(true);
        $http->method('getHttpCode')->willReturn(503);

        $service = new PrestashopService($http);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to retrieve option/');

        $service->getSpecificationsOption(99);
    }

    // ------------------------------------------------------------------ getSpecificationsCategory

    public function test_get_specifications_category_returns_entity_on_success(): void
    {
        $http = $this->mockHttp();
        $http->expects($this->once())->method('setUrl')->with('/categories/3?display=full');
        $http->expects($this->once())->method('invoke')->with('GET')->willReturnSelf();
        $http->expects($this->once())->method('failed')->willReturn(false);
        $http->expects($this->once())->method('toArray')->willReturn([
            'categories' => [
                ['id' => '3', 'id_parent' => '1', 'name' => 'Cani'],
            ],
        ]);

        $service = new PrestashopService($http);
        $result = $service->getSpecificationsCategory(3);

        $this->assertInstanceOf(CategoryEntity::class, $result);
        $this->assertSame(3, $result->getId());
    }

    public function test_get_specifications_category_throws_on_failure(): void
    {
        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willReturnSelf();
        $http->method('failed')->willReturn(true);
        $http->method('getHttpCode')->willReturn(500);

        $service = new PrestashopService($http);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to retrieve category/');

        $service->getSpecificationsCategory(3);
    }

    // ------------------------------------------------------------------ getSpecificationsStockAvailables

    public function test_get_specifications_stock_availables_returns_array_on_success(): void
    {
        $http = $this->mockHttp();
        $http->expects($this->once())->method('setUrl');
        $http->expects($this->once())->method('invoke')->with('GET')->willReturnSelf();
        $http->expects($this->once())->method('failed')->willReturn(false);
        $http->expects($this->once())->method('toArray')->willReturn([
            'stock_availables' => [
                ['id' => '1', 'id_product' => '10', 'id_product_attribute' => '0', 'quantity' => '5'],
            ],
        ]);

        $service = new PrestashopService($http);
        $result = $service->getSpecificationsStockAvailables(10);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertInstanceOf(StockAvailableEntity::class, $result[0]);
    }

    public function test_get_specifications_stock_availables_returns_empty_array_when_no_data(): void
    {
        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willReturnSelf();
        $http->method('failed')->willReturn(false);
        $http->method('toArray')->willReturn(['stock_availables' => []]);

        $service = new PrestashopService($http);
        $result = $service->getSpecificationsStockAvailables(99);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_get_specifications_stock_availables_throws_on_failure(): void
    {
        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willReturnSelf();
        $http->method('failed')->willReturn(true);
        $http->method('getHttpCode')->willReturn(500);

        $service = new PrestashopService($http);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to retrieve stock availables/');

        $service->getSpecificationsStockAvailables(10);
    }
}
