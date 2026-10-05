<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Domain\Entities\OrderEntity;
use PS\Webservice\Service\PS\PrestashopServiceInterface;

final class CustomerEntityDataTest extends TestCase
{
    private PrestashopServiceInterface $service;

    protected function setUp(): void
    {
        $this->service = $this->createMock(PrestashopServiceInterface::class);
    }

    public function testCustomerAddressesAndOptionalPasswordUseSourceData(): void
    {
        $customer = CustomerEntity::create([
            'email' => 'customer@example.com',
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'phone' => '111',
            'id_lang' => 3,
            'delivery_address' => [
                'alias' => 'delivery',
                'address1' => 'Main Street 1',
                'city' => 'London',
                'postcode' => 'N1',
                'id_country' => 44,
                'id_state' => 7,
                'phone_mobile' => '222',
            ],
            'invoice_address' => [
                'alias' => 'invoice',
                'address1' => 'Other Street 2',
                'city' => 'Oxford',
                'postcode' => 'OX1',
                'id_country' => 826,
                'id_state' => 9,
            ],
        ], $this->service)->toArray();

        self::assertNull($customer['password']);
        self::assertSame(3, $customer['id_lang']);
        self::assertSame(44, $customer['delivery_address']['id_country']);
        self::assertSame(7, $customer['delivery_address']['id_state']);
        self::assertSame('222', $customer['delivery_address']['phone_mobile']);
        self::assertSame(826, $customer['invoice_address']['id_country']);
        self::assertSame('Other Street 2', $customer['invoice_address']['address1']);
    }

    public function testOrderUsesCustomerLanguageAndPhoneWhenOrderValuesAreAbsent(): void
    {
        $address = [
            'address1' => 'Main Street 1',
            'city' => 'London',
            'postcode' => 'N1',
            'id_country' => 44,
        ];
        $order = OrderEntity::create([
            'id' => 1,
            'id_carrier' => 2,
            'id_guest' => 3,
            'id_customer' => 4,
            'reference' => 'ORDER1',
            'id_cart' => 5,
            'current_state' => 2,
            'date_add' => '2026-01-01',
            'total_paid_tax_incl' => 10,
            'total_paid_tax_excl' => 8,
            'customer' => [
                'email' => 'customer@example.com',
                'firstname' => 'Ada',
                'lastname' => 'Lovelace',
                'phone_mobile' => '222',
                'id_lang' => 3,
            ],
            'delivery_address' => $address,
            'invoice_address' => $address,
        ], $this->service)->toArray();

        self::assertSame(3, $order['id_lang']);
        self::assertSame('222', $order['customer']['phone']);
        self::assertSame(44, $order['customer']['delivery_address']['id_country']);
        self::assertSame(44, $order['customer']['invoice_address']['id_country']);
    }

    public function testAddressWithoutCountryIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Address country is required');

        CustomerEntity::create([
            'email' => 'customer@example.com',
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'delivery_address' => [
                'address1' => 'Main Street 1',
                'city' => 'London',
                'postcode' => 'N1',
            ],
        ], $this->service);
    }
}
