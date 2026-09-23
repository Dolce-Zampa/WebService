<?php
declare(strict_types=1);

use Mockery;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Entities\CarrierEntity;
use PS\Webservice\Domain\Entities\CartEntity;
use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Domain\Entities\ProductEntity;
use PS\Webservice\Domain\Models\PS\Customer;
use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Http\Controller\StripeWebhookController;
use PS\Webservice\Service\PS\Product;

final class OrderTest extends TestCase
{
    private $cache;
    private $taggedCache;
    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = $this->createMock(\Illuminate\Cache\Repository::class);
        $this->taggedCache = $this->createMock(\Illuminate\Cache\TaggedCache::class);

        $this->cache
            ->method('tags')
            ->willReturn($this->taggedCache);

        $this->taggedCache
            ->method('tags')
            ->willReturn($this->taggedCache);

        $app = new \Illuminate\Container\Container();

        $app->instance('cache', $this->cache);
        $app->instance(
            'log',
            $this->createMock(\Psr\Log\LoggerInterface::class)
        );

        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($app);


    }

    // {"id_cart":"4wW30E1r","customer":{"firstname":"Marco","lastname":"De Felice","email":"marco.defelice@dolcezampa.com","phone":"3319843630"},"invoice_address":{"address1":"Via Monte Rosa, 13","city":"Somma Lombardo","state":"VA","postcode":"21019","country":"IT"},"delivery_address":{"address1":"Via Monte Rosa, 13","city":"Somma Lombardo","state":"VA","postcode":"21019","country":"IT"},"id_carrier":15,"payment_method":"stripe","is_guest":true,"cart_rules":[],"id_guest":"r8Ogyz50"}
    public function test_create_new_order(): void
    {
        $this->cache
            ->method('tags')
            ->willReturn($this->taggedCache);

        $this->taggedCache
            ->method('tags')
            ->willReturn($this->taggedCache);

        $productStub = json_decode(file_get_contents(__DIR__ . '/stubs/product-entity.json'), TRUE);
        $productServiceMock = $this->createMock(Product::class);
        $productServiceMock->method('getProductById')->willReturn(ProductEntity::create($productStub, $productServiceMock));

        $requestMock = $this->createMock(\Psr\Http\Message\ServerRequestInterface::class);
        $responseMock = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $requestMock->method('getParsedBody')->willReturn([
            'id' => 1,
            'id_cart' => 1,
            'reference' => 'REF123',
            'current_state' => 2,
            'date_add' => '2026-01-01',
            'total_paid_tax_incl' => 41.32,
            'total_paid_tax_excl' => 38.00,
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
            'id_guest' => 11
        ]);

        $orderServiceMock = $this->createMock(\PS\Webservice\Service\PS\Order::class);
        $cartEntity = CartEntity::create(
            json_decode('{"id":204,"id_customer":null,"id_guest":123,"id_currency":1,"id_lang":1,"id_address_delivery":0,"id_address_invoice":0,"id_carrier":0,"secure_key":"d3ad39708e9fb664270e89dcb06d1040","totals":{"products_tax_incl":38,"shipping_tax_incl":3.32,"grand_total_tax_incl":41.32},"products":[{"id_product":204,"id_image":2647,"id_product_attribute":0,"id_customization":0,"name":"Collana Artigianale Halloween per Cani","reference":"SKU2026FC2000204","quantity":1,"price_wt":38,"total_wt":38,"customizations":[]}],"cart_rules":[]}', true),
            $orderServiceMock
        );
        $orderServiceMock->method('getProductPriceById')->willReturn(38.0);
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
                ],
                $orderServiceMock
            )
        );
        $orderServiceMock->method('getProductById')->willReturn(ProductEntity::create($productStub, $productServiceMock));

        $paymentServiceMock = $this->createMock(\PS\Webservice\Service\Payments\PaymentGatewayInterface::class);
        $paymentServiceMock->method('createPaymentSession')->willReturnCallback(function (OrderSession $orderSession): string {
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
        $body = json_decode((string) $response->getBody(), true);

        $this->assertEquals('http://checkout.url', $body['data']['payment_url']);
        $this->assertEquals($cartEntity->toArray(), $body['data']['order']);

    }

    public function test_create_new_order_from_recovery_abbandoned_cart(): void
    {
        $productStub = json_decode(file_get_contents(__DIR__ . '/stubs/product-entity.json'), TRUE);
        $productServiceMock = $this->createMock(Product::class);
        $productMock = ProductEntity::create($productStub, $productServiceMock);

        $this->cache
            ->method('tags')
            ->willReturn($this->taggedCache);

        $this->taggedCache
            ->method('tags')
            ->willReturn($this->taggedCache);

        \Illuminate\Database\Capsule\Manager::schema()->create('product', function ($table) {
            $table->integer('id_product')->primary();
            $table->string('name');
            $table->decimal('price', 10, 2);
        });

        \Illuminate\Database\Capsule\Manager::schema()->create('customer', function ($table) {
            $table->integer('id_customer')->primary();
            $table->string('firstname');
            $table->string('lastname');
            $table->string('email');
        });

        $this->taggedCache->method('has')->willReturn(true);
        $this->taggedCache->method('get')->willReturnCallback(function ($key) use($productMock) {
            return match ($key) {
                '10379441e2371afb1ae23c4caffc79a91b3b5c4d' => $productMock->toArray(),
                '40bd001563085fc35165329ea1ff5c5ecbdbbeef' => OrderSession::create([
                    'cart_id' => 204,
                    'id_customer' => null,
                    'id_guest' => 123,
                    'id_carrier' => 0,
                    'customer' => CustomerEntity::create([
                        'id' => null,
                        'email' => 'john.doe@example.com',
                        'firstname' => 'John',
                        'lastname' => 'Doe',
                        'phone' => null,
                        'delivery_address' => null,
                        'newsletter' => false,
                        'invoice_address' => null,
                    ], $this->createMock(\PS\Webservice\Service\PS\Order::class))
                ], $this->createMock(\PS\Webservice\Service\PS\Order::class)),
                default => null,
            };
        });

        Customer::create(
            [
                'id_customer' => 456,
                'firstname' => 'John',
                'lastname' => 'Doe',
                'email' => 'john.doe@example.com'
            ]
        );
        $productServiceMock->method('getProductById')->willReturn($productMock);

        $session = new \Stripe\StripeObject();
        $metadata = new \Stripe\StripeObject();
        $metadata->cart_id = 123;
        $metadata->id_customer = 456;
        $metadata->id_guest = null;
        $metadata->id_carrier = 1;
        $metadata->customer_email = 'john.doe@example.com';
        $session->metadata = $metadata;

        $orderServiceMock = $this->createMock(\PS\Webservice\Service\PS\Order::class);
        $cartEntity = CartEntity::create(
            json_decode('{"id":204,"id_customer":null,"id_guest":123,"id_currency":1,"id_lang":1,"id_address_delivery":0,"id_address_invoice":0,"id_carrier":0,"secure_key":"d3ad39708e9fb664270e89dcb06d1040","totals":{"products_tax_incl":38,"shipping_tax_incl":3.32,"grand_total_tax_incl":41.32},"products":[{"id_product":204,"id_image":2647,"id_product_attribute":0,"id_customization":0,"name":"Collana Artigianale Halloween per Cani","reference":"SKU2026FC2000204","quantity":1,"price_wt":38,"total_wt":38,"customizations":[]}],"cart_rules":[]}', true),
            $orderServiceMock
        );
        $orderServiceMock->method('getProductPriceById')->willReturn(38.0);
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
                ],
                $orderServiceMock
            )
        );
        $orderServiceMock->method('getProductById')->willReturn(ProductEntity::create($productStub, $productServiceMock));

        $paymentServiceMock = $this->createMock(\PS\Webservice\Service\Payments\PaymentGatewayInterface::class);
        $paymentServiceMock->method('createPaymentSession')->willReturnCallback(function (OrderSession $orderSession): string {
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
                'coupon_code',
                'recovery_attempt',
                'customer_email'
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

        $mailerServiceMock = $this->createMock(\PS\Webservice\Service\MailerInterface::class);
        $mailerServiceMock->method('sendRecoveryCartExpired')->willReturnCallback(function ($customerEmail, $paymentUrl, $lineItems, $orderTotal, $customerFirstName) {
            $this->assertEquals('john.doe@example.com', $customerEmail);
            $this->assertNotEmpty($paymentUrl);
            $this->assertNotEmpty($lineItems);
            $this->assertEquals('44.1', $orderTotal);
            $this->assertEquals('John', $customerFirstName);
        });

        $mailjetServiceMock = $this->createMock(\PS\Webservice\Service\MailjetService::class);

        $controller = new StripeWebhookController(
            $orderServiceMock,
            $mailjetServiceMock,
            $paymentServiceMock,
            $mailerServiceMock,
        );
        $controller->handleCheckoutSessionExpired($session);

    }

    public function test_webhook_stripe_checkout_session_expired(): void
    {
        $productStub = json_decode(file_get_contents(__DIR__ . '/stubs/product-entity.json'), TRUE);
        $productServiceMock = $this->createMock(Product::class);
        $productMock = ProductEntity::create($productStub, $productServiceMock);

        $this->cache
            ->method('tags')
            ->willReturn($this->taggedCache);

        $this->taggedCache
            ->method('tags')
            ->willReturn($this->taggedCache);

        $this->taggedCache->method('has')->willReturn(true);
        
        $this->taggedCache->method('get')->willReturnCallback(function ($key) use ($productMock) {
            return match ($key) {
                '10379441e2371afb1ae23c4caffc79a91b3b5c4d' => $productMock->toArray(),
                '51eac6b471a284d3341d8c0c63d0f1a286262a18' => OrderSession::create([
                    'cart_id' => 204,
                    'id_customer' => null,
                    'id_guest' => 123,
                    'id_carrier' => 0,
                    'customer' => CustomerEntity::create([
                        'id' => null,
                        'email' => 'john.doe@example.com',
                        'firstname' => 'John',
                        'lastname' => 'Doe',
                        'phone' => null,
                        'delivery_address' => null,
                        'newsletter' => false,
                        'invoice_address' => null,
                    ], $this->createMock(\PS\Webservice\Service\PS\Order::class))
                ], $this->createMock(\PS\Webservice\Service\PS\Order::class)),
                default => null,
            };
        });

        // This test should simulate a Stripe checkout session expired event
        // and assert that the appropriate methods are called and the correct
        // data is passed to the mailer service.
        $requestMock = $this->createMock(\Psr\Http\Message\ServerRequestInterface::class);
        $responseMock = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $requestMock->method('getParsedBody')->willReturn(json_decode(file_get_contents(__DIR__ . '/stubs/session-stripe-expired.json'), true));

        ////////// 
        $productStub = json_decode(file_get_contents(__DIR__ . '/stubs/product-entity.json'), TRUE);
        $productServiceMock = $this->createMock(Product::class);
        $productServiceMock->method('getProductById')->willReturn(ProductEntity::create($productStub, $productServiceMock));

        $orderServiceMock = $this->createMock(\PS\Webservice\Service\PS\Order::class);
        $cartEntity = CartEntity::create(
            json_decode('{"id":204,"id_customer":null,"id_guest":123,"id_currency":1,"id_lang":1,"id_address_delivery":0,"id_address_invoice":0,"id_carrier":0,"secure_key":"d3ad39708e9fb664270e89dcb06d1040","totals":{"products_tax_incl":38,"shipping_tax_incl":3.32,"grand_total_tax_incl":41.32},"products":[{"id_product":204,"id_image":2647,"id_product_attribute":0,"id_customization":0,"name":"Collana Artigianale Halloween per Cani","reference":"SKU2026FC2000204","quantity":1,"price_wt":38,"total_wt":38,"customizations":[]}],"cart_rules":[]}', true),
            $orderServiceMock
        );
        $orderServiceMock->method('getProductPriceById')->willReturn(38.0);
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
                ],
                $orderServiceMock
            )
        );
        $orderServiceMock->method('getProductById')->willReturn($productMock);

        $paymentServiceMock = $this->createMock(\PS\Webservice\Service\Payments\PaymentGatewayInterface::class);
        $paymentServiceMock->method('createPaymentSession')->willReturnCallback(function (OrderSession $orderSession): string {
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
                'coupon_code',
                'recovery_attempt',
                'customer_email'
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

        $mailerServiceMock = $this->createMock(\PS\Webservice\Service\MailerInterface::class);
        $mailerServiceMock->method('sendRecoveryCartExpired')->willReturnCallback(function ($customerEmail, $paymentUrl, $lineItems, $orderTotal, $customerFirstName) {
            $this->assertEquals('john.doe@example.com', $customerEmail);
            $this->assertNotEmpty($paymentUrl);
            $this->assertNotEmpty($lineItems);
            $this->assertEquals('44.1', $orderTotal);
            $this->assertEquals('John', $customerFirstName);
        });

        $mailjetServiceMock = $this->createMock(\PS\Webservice\Service\MailjetService::class);
        $orderRepositorymock = $this->createMock(\PS\Webservice\Repositories\OrderRepository::class);

        $controller = $this->getMockBuilder(StripeWebhookController::class)
            ->setConstructorArgs([
                $orderServiceMock,
                $mailjetServiceMock,
                $paymentServiceMock,
                $mailerServiceMock,
                $orderRepositorymock
            ])
            ->onlyMethods(['constructStripeEvent'])
            ->getMock();

        $controller->method('constructStripeEvent')
            ->willReturn(\Stripe\Event::constructFrom(json_decode(file_get_contents(__DIR__ . '/stubs/session-stripe-expired.json'), true)));

        // 3. Esegui il test normalmente
        $response = $controller->handleWebhook($requestMock, $responseMock, []);
        $this->assertEquals(200, $response->getStatusCode());

    }

    public function text_stripe_webhook_session_completed()
    {
        $this->cache
            ->method('tags')
            ->willReturn($this->taggedCache);

        $this->taggedCache
            ->method('tags')
            ->willReturn($this->taggedCache);

        // This test should simulate a Stripe checkout session expired event
        // and assert that the appropriate methods are called and the correct
        // data is passed to the mailer service.
        $requestMock = $this->createMock(\Psr\Http\Message\ServerRequestInterface::class);
        $responseMock = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $requestMock->method('getParsedBody')->willReturn(json_decode(file_get_contents(__DIR__ . '/stubs/session-stripe-completed.json'), true));

        ////////// 
        $productStub = json_decode(file_get_contents(__DIR__ . '/stubs/product-entity.json'), TRUE);
        $productServiceMock = $this->createMock(Product::class);
        $productServiceMock->method('getProductById')->willReturn(ProductEntity::create($productStub, $productServiceMock));

        $orderServiceMock = $this->createMock(\PS\Webservice\Service\PS\Order::class);
        $cartEntity = CartEntity::create(
            json_decode('{"id":204,"id_customer":null,"id_guest":123,"id_currency":1,"id_lang":1,"id_address_delivery":0,"id_address_invoice":0,"id_carrier":0,"secure_key":"d3ad39708e9fb664270e89dcb06d1040","totals":{"products_tax_incl":38,"shipping_tax_incl":3.32,"grand_total_tax_incl":41.32},"products":[{"id_product":204,"id_image":2647,"id_product_attribute":0,"id_customization":0,"name":"Collana Artigianale Halloween per Cani","reference":"SKU2026FC2000204","quantity":1,"price_wt":38,"total_wt":38,"customizations":[]}],"cart_rules":[]}', true),
            $orderServiceMock
        );
        $orderServiceMock->method('getProductPriceById')->willReturn(38.0);
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
                ],
                $orderServiceMock
            )
        );
        $orderServiceMock->method('getProductById')->willReturn(ProductEntity::create($productStub, $productServiceMock));

        $paymentServiceMock = $this->createMock(\PS\Webservice\Service\Payments\PaymentGatewayInterface::class);
        $paymentServiceMock->method('createPaymentSession')->willReturnCallback(function (OrderSession $orderSession): string {
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

        $mailerServiceMock = $this->createMock(\PS\Webservice\Service\MailerInterface::class);
        $mailerServiceMock->method('sendRecoveryCartExpired')->willReturnCallback(function ($customerEmail, $paymentUrl, $lineItems, $orderTotal, $customerFirstName) {
            $this->assertEquals('john.doe@example.com', $customerEmail);
            $this->assertNotEmpty($paymentUrl);
            $this->assertNotEmpty($lineItems);
            $this->assertEquals('44.1', $orderTotal);
            $this->assertEquals('John', $customerFirstName);
        });

        $mailjetServiceMock = $this->createMock(\PS\Webservice\Service\MailjetService::class);
        $mailjetServiceMock->method('createNewContact')->willReturn(true);
        $mailjetServiceMock->method('setContactListSubscription')->willReturn(true);
        $orderRepositorymock = $this->createMock(\PS\Webservice\Repositories\OrderRepository::class);

        $controller = $this->getMockBuilder(StripeWebhookController::class)
            ->setConstructorArgs([
                $orderServiceMock,
                $mailjetServiceMock,
                $paymentServiceMock,
                $mailerServiceMock,
                $orderRepositorymock
            ])
            ->onlyMethods(['constructStripeEvent'])
            ->getMock();

        $controller->method('constructStripeEvent')
            ->willReturn(\Stripe\Event::constructFrom(json_decode(file_get_contents(__DIR__ . '/stubs/session-stripe-completed.json'), true)));

        // 3. Esegui il test normalmente
        $response = $controller->handleWebhook($requestMock, $responseMock, []);
        $this->assertEquals(200, $response->getStatusCode());

    }
}