<?php
declare(strict_types=1);

use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Service\HttpServiceInterface;
use PS\Webservice\Service\PS\Customer;
use PHPUnit\Framework\TestCase;

final class CustomerServiceTest extends TestCase
{
    private function mockHttp(): HttpServiceInterface
    {
        return $this->createMock(HttpServiceInterface::class);
    }

    private function sampleCustomerResponse(): array
    {
        return [
            'data' => [
                'customer' => [
                    'id' => 7,
                    'email' => 'john@example.com',
                    'firstname' => 'John',
                    'lastname' => 'Doe',
                    'newsletter' => false,
                    'phone' => '3331234567',
                ],
                'addresses' => [
                    [
                        'alias' => 'home',
                        'phone' => '3331234567',
                        'address1' => 'Via Roma 1',
                        'city' => 'Milano',
                        'postcode' => '20100',
                        'id_country' => '8',
                    ],
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------ login

    public function test_login_returns_customer_entity_on_success(): void
    {
        $http = $this->mockHttp();
        $http->expects($this->once())->method('setUrl')->with('/login?no_cache=1');
        $http->expects($this->once())->method('invoke')->willReturnSelf();
        $http->expects($this->once())->method('toArray')->willReturn($this->sampleCustomerResponse());

        $service = new Customer($http);
        $result = $service->login(['email' => 'john@example.com', 'password' => 'secret']);

        $this->assertInstanceOf(CustomerEntity::class, $result);
        $this->assertSame('john@example.com', $result->email);
    }

    public function test_login_returns_null_when_customer_data_is_absent(): void
    {
        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willReturnSelf();
        $http->method('toArray')->willReturn(['data' => []]);

        $service = new Customer($http);
        $result = $service->login(['email' => 'unknown@example.com', 'password' => 'wrong']);

        $this->assertNull($result);
    }

    // ------------------------------------------------------------------ register

    public function test_register_returns_customer_entity_on_success(): void
    {
        $http = $this->mockHttp();
        $http->expects($this->once())->method('setUrl')->with('/register?noc_cache=1');
        $http->expects($this->once())->method('invoke')->willReturnSelf();
        $http->expects($this->once())->method('toArray')->willReturn($this->sampleCustomerResponse());

        $customerEntity = CustomerEntity::create([
            'id' => null,
            'email' => 'john@example.com',
            'firstname' => 'John',
            'lastname' => 'Doe',
            'phone' => '3331234567',
            'newsletter' => false,
            'delivery_address' => [
                'alias' => 'home',
                'address1' => 'Via Roma 1',
                'city' => 'Milano',
                'postcode' => '20100',
            ],
        ], $this->createMock(\PS\Webservice\Service\PS\PrestashopServiceInterface::class));

        $service = new Customer($http);
        $result = $service->register($customerEntity);

        $this->assertInstanceOf(CustomerEntity::class, $result);
        $this->assertSame('john@example.com', $result->email);
    }

    public function test_register_returns_null_when_customer_data_is_absent(): void
    {
        $http = $this->mockHttp();
        $http->method('setUrl');
        $http->method('invoke')->willReturnSelf();
        $http->method('toArray')->willReturn(['data' => []]);

        $customerEntity = CustomerEntity::create([
            'id' => null,
            'email' => 'new@example.com',
            'firstname' => 'New',
            'lastname' => 'User',
            'phone' => '0000000000',
            'newsletter' => false,
            'delivery_address' => [
                'alias' => 'home',
                'address1' => 'Via Test 1',
                'city' => 'Roma',
                'postcode' => '00100',
            ],
        ], $this->createMock(\PS\Webservice\Service\PS\PrestashopServiceInterface::class));

        $service = new Customer($http);
        $result = $service->register($customerEntity);

        $this->assertNull($result);
    }
}
