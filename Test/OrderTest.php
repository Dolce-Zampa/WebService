<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Entities\CarrierEntity;
use PS\Webservice\Domain\Entities\CartEntity;
use PS\Webservice\Domain\Object\OrderSession;

final class OrderTest extends TestCase
{
    
    // {"id_cart":"4wW30E1r","customer":{"firstname":"Marco","lastname":"De Felice","email":"marco.defelice@dolcezampa.com","phone":"3319843630"},"invoice_address":{"address1":"Via Monte Rosa, 13","city":"Somma Lombardo","state":"VA","postcode":"21019","country":"IT"},"delivery_address":{"address1":"Via Monte Rosa, 13","city":"Somma Lombardo","state":"VA","postcode":"21019","country":"IT"},"id_carrier":15,"payment_method":"stripe","is_guest":true,"cart_rules":[],"id_guest":"r8Ogyz50"}
    public function testCreateNewOrder(): void
    {
        $requestMock = $this->createMock(\Psr\Http\Message\ServerRequestInterface::class);
        $responseMock = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $requestMock->method('getParsedBody')->willReturn([
            'id_cart' => '4wW30E1r',
            'customer' => [
                'firstname' => 'Marco',
                'lastname' => 'De Felice',
                'email' => 'marco.defelice@dolcezampa.com',
                'phone' => '3319843630'
            ],
            'invoice_address' => [
                'address1' => 'Via Monte Rosa, 13',
                'city' => 'Somma Lombardo',
                'state' => 'VA',
                'postcode' => '21019',
                'country' => 'IT'
            ],
            'delivery_address' => [
                'address1' => 'Via Monte Rosa, 13',
                'city' => 'Somma Lombardo',
                'state' => 'VA',
                'postcode' => '21019',
                'country' => 'IT'
            ],
            'id_carrier' => 15,
            'payment_method' => 'stripe',
            'is_guest' => true,
            'cart_rules' => [],
            'id_guest' => 'r8Ogyz50'
        ]);

        $orderServiceMock = $this->createMock(\PS\Webservice\Service\PS\Order::class);
        $cartEntity = CartEntity::create(
            json_decode('{"id":"P9OEVPWj","id_customer":null,"id_guest":"QPOw8W9E","id_currency":1,"id_lang":1,"id_address_delivery":0,"id_address_invoice":0,"id_carrier":0,"secure_key":"d3ad39708e9fb664270e89dcb06d1040","totals":{"products_tax_incl":38,"shipping_tax_incl":3.32,"grand_total_tax_incl":41.32},"products":[{"id_product":204,"id_image":2647,"id_product_attribute":0,"id_customization":0,"name":"Collana Artigianale Halloween per Cani","reference":"SKU2026FC2000204","quantity":1,"price_wt":38,"total_wt":38,"customizations":[]}],"cart_rules":[]}'),
            $orderServiceMock
        );
        $orderServiceMock->method('getProductPriceById')->willReturn(38); 
        $orderServiceMock->method('getCartFromId')->willReturn(
            $cartEntity
        );
        $orderServiceMock->method('getCarrierDetail')->willReturn(
            CarrierEntity::create(
                [
                    'deleted' => "0",
                    'is_module' => "1",
                    'id_tax_rules_group' => 0,
                    'id_reference' => 15,
                    'name' => "BRT Corriere",
                    'active' => "1",
                    'is_free' => "0",
                    'url' => "",
                    'shipping_handling' => "0",
                    'shipping_external' => "1",
                    'range_behavior' => "0",
                    'shipping_method' => 1,
                    'max_width' => 0,
                    'max_height' => 0,
                    'max_depth' => 0,
                    'max_weight' => "0.000000",
                    'grade' => 0,
                    'external_module_name' => "packlink",
                    'need_range' => "1",
                    'position' => 2,
                    'delay' => "2 DAYS",
                    'price_with_tax' => "6.10"
                ], $orderServiceMock
            )
        );


        $paymentServiceMock = $this->createMock(\PS\Webservice\Service\Payments\PaymentGatewayInterface::class);
        $paymentServiceMock->method('createPaymentSession')->willReturnCallback(function(OrderSession $orderSession) {
            //check stripe keys
            //check Stripe payload is it correct
            $expectedSessionKeys = [
                'mode',
                'success_url',
                'cancel_url',
                'expires_at',
                'line_items',
                'metadata',
            ];

            $expectedMetadataKeys = [
                'cart_id',
                'id_customer',
                'id_guest',
                'id_carrier',
                'customer',
                'coupon_code',
            ];

            $toCheck = $orderSession->toArray();
            foreach ($expectedSessionKeys as $key) {
                if (!array_key_exists($key, $toCheck)) {
                    throw new \Exception("Missing expected session key: $key");
                }
            }

            foreach ($expectedMetadataKeys as $key) {
                if (!array_key_exists($key, $toCheck['metadata'])) {
                    throw new \Exception("Missing expected metadata key: $key");
                }
            }

            return 'http://checkout.url';
        });

        $controller = new \PS\Webservice\Http\Controller\OrderController($orderServiceMock, $paymentServiceMock);
        $response = $controller->createOrder($requestMock, $responseMock, []);

        $this->assertEquals('http://checkout.url', $response['payment_url']);
        $this->assertEquals($cartEntity->toArray(), $response['cart']);

    }
}