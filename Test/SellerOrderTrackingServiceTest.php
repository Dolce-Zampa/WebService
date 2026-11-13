<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\HttpServiceInterface;
use PS\Webservice\Service\PS\PrestashopService;

final class SellerOrderTrackingServiceTest extends TestCase
{
    public function test_batch_request_returns_only_the_authorized_order_ids(): void
    {
        $http = $this->createMock(HttpServiceInterface::class);
        $http->expects(self::once())->method('setUrl')->with('/orders?tracking_order_ids=42%2C43');
        $http->expects(self::once())->method('invoke')->with('GET')->willReturnSelf();
        $http->method('failed')->willReturn(false);
        $http->method('toArray')->willReturn(['data' => ['tracking_by_order' => [
            42 => ['number' => 'BRT42', 'url' => 'https://carrier.example/42'],
            99 => ['number' => 'OTHER', 'url' => 'https://carrier.example/99'],
        ]]]);
        $service = new PrestashopService($http);
        self::assertSame([42 => ['number' => 'BRT42', 'url' => 'https://carrier.example/42']], $service->getTrackingForOrders([42, 43]));
    }
}
