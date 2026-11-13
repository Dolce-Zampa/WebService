<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Http\Controller\CarrierController;
use PS\Webservice\Service\HttpServiceInterface;
use PS\Webservice\Service\PS\Carrier;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class CarrierRatesApiTest extends TestCase
{
    public function test_list_contains_the_prestashop_prices_and_preserves_ranges(): void
    {
        $http = $this->createMock(HttpServiceInterface::class);
        $http->expects(self::once())->method('setUrl')->with('/carrier_rates');
        $http->method('invoke')->with('GET')->willReturnSelf();
        $http->method('failed')->willReturn(false);
        $carrier = ['id' => 20, 'name' => 'inPost', 'delay' => '3', 'grade' => 2,
            'active' => '1', 'deleted' => '0', 'is_free' => '0', 'shipping_handling' => '0',
            'shipping_external' => '0', 'range_behavior' => '0', 'shipping_method' => 1,
            'max_width' => 0, 'max_height' => 0, 'max_depth' => 0, 'max_weight' => '5',
            'price_with_tax' => 3.9, 'price_ranges' => [['min' => 0, 'max' => 5, 'price_with_tax' => 3.9]],
            'price_varies_by_range' => false];
        $http->method('toArray')->willReturn(['success' => true, 'data' => ['carriers' => [$carrier]]]);
        $controller = new CarrierController(new Carrier($http));
        $response = $controller->carrierList((new ServerRequestFactory())->createServerRequest('GET', '/api/carriers'), new Response());
        $data = json_decode((string) $response->getBody(), true)['data'];
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(3.9, $data[0]['price_with_tax']);
        self::assertSame(5, $data[0]['price_ranges'][0]['max']);
        self::assertFalse($data[0]['price_varies_by_range']);
    }
}
