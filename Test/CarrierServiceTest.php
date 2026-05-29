<?php
declare(strict_types=1);

use PS\Webservice\Domain\Entities\CarrierEntity;
use PS\Webservice\Service\HttpServiceInterface;
use PS\Webservice\Service\PS\Carrier;
use PHPUnit\Framework\TestCase;

final class CarrierServiceTest extends TestCase
{
    private function mockHttp(): HttpServiceInterface
    {
        return $this->createMock(HttpServiceInterface::class);
    }

    private function sampleCarrierData(): array
    {
        return [
            'id' => '3',
            'name' => 'GLS',
            'delay' => [['id' => '1', 'value' => '2-3 giorni']],
            'active' => '1',
            'deleted' => '0',
            'is_free' => '0',
            'shipping_handling' => '0',
            'range_behavior' => '0',
        ];
    }

    // ------------------------------------------------------------------ carriersList

    public function test_carriers_list_returns_collection_with_entities(): void
    {
        $http = $this->mockHttp();
        $http->expects($this->once())
            ->method('setUrl')
            ->with($this->stringContains('/carriers?'));

        $http->expects($this->once())->method('invoke')->with('GET')->willReturnSelf();
        $http->expects($this->once())->method('failed')->willReturn(false);
        $http->expects($this->once())->method('toArray')->willReturn([
            'carriers' => [$this->sampleCarrierData()],
        ]);

        $service = new Carrier($http);
        $result = $service->carriersList();

        $this->assertCount(1, $result);
        $this->assertInstanceOf(CarrierEntity::class, $result->first());
    }

    public function test_carriers_list_returns_empty_collection_when_no_carriers(): void
    {
        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willReturnSelf();
        $http->method('failed')->willReturn(false);
        $http->method('toArray')->willReturn(['carriers' => []]);

        $service = new Carrier($http);
        $result = $service->carriersList();

        $this->assertCount(0, $result);
    }

    public function test_carriers_list_throws_runtime_exception_on_failure(): void
    {
        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willReturnSelf();
        $http->method('failed')->willReturn(true);
        $http->method('getHttpCode')->willReturn(500);

        $service = new Carrier($http);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to retrieve carriers/');

        $service->carriersList();
    }

    // ------------------------------------------------------------------ getCarrierDetail

    public function test_get_carrier_detail_returns_entity_on_success(): void
    {
        $http = $this->mockHttp();
        $http->expects($this->once())->method('setUrl')->with('/carriers/3?display=full');
        $http->expects($this->once())->method('invoke')->with('GET')->willReturnSelf();
        $http->expects($this->once())->method('failed')->willReturn(false);
        $http->expects($this->once())->method('toArray')->willReturn([
            'carriers' => [$this->sampleCarrierData()],
        ]);

        $service = new Carrier($http);
        $result = $service->getCarrierDetail(3);

        $this->assertInstanceOf(CarrierEntity::class, $result);
    }

    public function test_get_carrier_detail_returns_null_on_404(): void
    {
        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willReturnSelf();
        $http->method('failed')->willReturn(true);
        $http->method('getHttpCode')->willReturn(404);

        $service = new Carrier($http);
        $result = $service->getCarrierDetail(999);

        $this->assertNull($result);
    }

    public function test_get_carrier_detail_throws_on_non_404_failure(): void
    {
        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willReturnSelf();
        $http->method('failed')->willReturn(true);
        $http->method('getHttpCode')->willReturn(503);

        $service = new Carrier($http);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to retrieve carrier detail/');

        $service->getCarrierDetail(3);
    }

    public function test_get_carrier_detail_returns_null_when_response_has_no_carrier_data(): void
    {
        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willReturnSelf();
        $http->method('failed')->willReturn(false);
        $http->method('toArray')->willReturn(['carriers' => []]);

        $service = new Carrier($http);
        $result = $service->getCarrierDetail(3);

        $this->assertNull($result);
    }
}
