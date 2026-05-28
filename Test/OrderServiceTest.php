<?php
declare(strict_types=1);

use PS\Webservice\Domain\Object\WebserviceConfig;
use PS\Webservice\Service\HttpServiceInterface;
use PS\Webservice\Service\PS\Order;
use PHPUnit\Framework\TestCase;

final class OrderServiceTest extends TestCase
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

    private function sampleOrderRow(): array
    {
        return [
            'id' => 1,
            'reference' => 'AZBCZYX',
            'id_cart' => 42,
            'current_state' => 5,
            'date_add' => '2024-06-01 10:00:00',
            'total_paid_tax_incl' => 55.00,
            'total_paid_tax_excl' => 45.08,
            'id_lang' => 1,
            'delivery_address' => [
                'alias' => 'home',
                'address1' => 'Via Roma 1',
                'city' => 'Milano',
                'postcode' => '20100',
            ],
            'invoice_address' => [
                'alias' => 'home',
                'address1' => 'Via Roma 1',
                'city' => 'Milano',
                'postcode' => '20100',
            ],
            'customer' => [
                'id' => 7,
                'email' => 'john@example.com',
                'firstname' => 'John',
                'lastname' => 'Doe',
                'phone' => '3331234567',
                'phone_mobile' => '3331234567',
                'newsletter' => false,
                'delivery_address' => [
                    'alias' => 'home',
                    'address1' => 'Via Roma 1',
                    'city' => 'Milano',
                    'postcode' => '20100',
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------ getOrderListFromUserId

    public function test_get_order_list_returns_array_on_success(): void
    {
        $http = $this->mockHttp();
        $http->expects($this->once())->method('setUrl')->with($this->stringContains('/orders?'));
        $http->expects($this->once())->method('invoke')->with('GET')->willReturnSelf();
        $http->expects($this->once())->method('getHttpCode')->willReturn(200);
        $http->expects($this->once())->method('toArray')->willReturn([
            'data' => [
                'orders' => [$this->sampleOrderRow()],
            ],
        ]);

        $service = new Order($http);
        $result = $service->getOrderListFromUserId(null);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    public function test_get_order_list_returns_null_on_http_error(): void
    {
        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willReturnSelf();
        $http->method('getHttpCode')->willReturn(500);
        $http->method('toArray')->willReturn([]);

        $service = new Order($http);
        $result = $service->getOrderListFromUserId(null);

        $this->assertNull($result);
    }

    public function test_get_order_list_returns_null_when_orders_key_missing(): void
    {
        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willReturnSelf();
        $http->method('getHttpCode')->willReturn(200);
        $http->method('toArray')->willReturn(['data' => []]);

        $service = new Order($http);
        $result = $service->getOrderListFromUserId(null);

        $this->assertNull($result);
    }

    // ------------------------------------------------------------------ orderDetails

    public function test_order_details_returns_entity_on_success(): void
    {
        $http = $this->mockHttp();

        // Encode a real orderId using the same UuidGenerator logic the service uses
        $service = new Order($http);
        $encodedOrderId = $service->encodeId(1, 'order');

        $responseData = [
            'data' => [
                'order' => $this->sampleOrderRow(),
                'customer' => $this->sampleOrderRow()['customer'],
            ],
        ];

        $http->expects($this->once())->method('setUrl')->with($this->stringContains('/orders?'));
        $http->expects($this->once())->method('invoke')->with('GET')->willReturnSelf();
        $http->method('toArray')->willReturn($responseData);

        $result = $service->orderDetails($encodedOrderId);

        $this->assertInstanceOf(\PS\Webservice\Domain\Entities\OrderEntity::class, $result);
    }

    public function test_order_details_returns_null_on_exception(): void
    {
        $http = $this->mockHttp();
        $service = new Order($http);
        $encodedOrderId = $service->encodeId(99, 'order');

        $http->method('setUrl');
        $http->method('invoke')->willThrowException(new \RuntimeException('connection error'));

        $result = $service->orderDetails($encodedOrderId);

        $this->assertNull($result);
    }
}
