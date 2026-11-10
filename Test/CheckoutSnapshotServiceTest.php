<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Service\Payments\CheckoutSnapshotService;
use PS\Webservice\Service\GiftCard\GiftCardTender;
use PS\Webservice\Service\PS\Order;

final class CheckoutSnapshotServiceTest extends TestCase
{
    public function test_virtual_cart_snapshot_keeps_zero_carrier_and_no_shipping(): void
    {
        $db = $this->database();
        $order = $this->createMock(Order::class);
        $session = OrderSession::create([
            'cart_id' => 42, 'id_customer' => 9, 'id_carrier' => 0, 'is_virtual' => true,
            'customer' => CustomerEntity::create(['email' => 'buyer@example.test', 'firstname' => 'Test', 'lastname' => 'Customer'], $order),
        ], $order);
        $product = ['id_product' => 22, 'id_manufacturer' => 7, 'name' => 'Gift card', 'price_wt' => '20.00', 'quantity' => 1];
        $session->addCartLineItem($product);
        $service = new CheckoutSnapshotService($db);
        $snapshot = $service->create($session, ['currency_iso' => 'EUR', 'is_virtual' => true,
            'products' => [$product], 'totals' => ['shipping_tax_incl' => 0]]);
        $resolved = $service->resolveForStripeSession($snapshot['id'], \Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_virtual', 'amount_total' => 2000, 'currency' => 'eur',
            'metadata' => ['checkout_snapshot_id' => $snapshot['id']],
        ]));
        self::assertSame(0, $resolved['payload']['carrier_id']);
        self::assertSame(0, $resolved['payload']['totals']['shipping_cents']);
    }

    private string|false $previousAppKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousAppKey = getenv('APP_KEY');
        putenv('APP_KEY=checkout-snapshot-test-key');
        $_ENV['APP_KEY'] = 'checkout-snapshot-test-key';
    }

    protected function tearDown(): void
    {
        if ($this->previousAppKey === false) {
            putenv('APP_KEY');
            unset($_ENV['APP_KEY']);
        } else {
            putenv('APP_KEY=' . $this->previousAppKey);
            $_ENV['APP_KEY'] = $this->previousAppKey;
        }
        parent::tearDown();
    }

    public function test_persists_encrypted_server_quote_and_binds_it_to_the_stripe_session(): void
    {
        $db = $this->database();
        $db->table('manufacturer_details')->insert(['id_manufacturer' => 7, 'commission' => '17.50']);
        $order = $this->createMock(Order::class);
        $session = OrderSession::create([
            'cart_id' => 42,
            'id_customer' => 9,
            'id_carrier' => 3,
            'payment_module' => 'webserviceapi',
            'customer' => CustomerEntity::create([
                'email' => 'buyer@example.test',
                'firstname' => 'Buyer',
                'lastname' => 'Example',
                'delivery_address' => ['address1' => 'Via Roma 1', 'city' => 'Roma', 'postcode' => '00100', 'country' => 'IT'],
            ], $order),
        ], $order);
        $session->addCartLineItem(['id_product' => 10, 'id_product_attribute' => 4, 'id_customization' => 2, 'id_manufacturer' => 7, 'manufacturer_name' => 'Venditore', 'name' => 'Collare', 'reference' => 'COL-10', 'price_wt' => '60.00', 'quantity' => 1]);
        $session->addCartShipping(['totals' => ['shipping_tax_incl' => '6.10']]);

        $service = new CheckoutSnapshotService($db);
        $snapshot = $service->create($session, [
            'currency_iso' => 'EUR',
            'products' => [[
                'id_product' => 10, 'id_product_attribute' => 4, 'id_customization' => 2,
                'id_manufacturer' => 7, 'manufacturer_name' => 'Venditore', 'name' => 'Collare', 'reference' => 'COL-10',
                'price_wt' => '60.00', 'price_tax_excl' => '49.18', 'total_tax_excl' => '49.18', 'quantity' => 1,
            ]],
            'totals' => ['shipping_tax_incl' => '6.10'],
        ]);
        $session->setCheckoutSnapshotId($snapshot['id']);

        $stored = $db->table('stripe_checkout_snapshots')->where('id', $snapshot['id'])->first();
        self::assertNotNull($stored);
        self::assertStringStartsWith('encrypted:v1:', $stored->payload_ciphertext);
        self::assertStringNotContainsString('buyer@example.test', $stored->payload_ciphertext);

        $service->bindStripeSession($snapshot['id'], 'cs_snapshot_42', 'pi_snapshot_42', 'dz_cart_42');
        $resolved = $service->resolveForStripeSession($snapshot['id'], \Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_snapshot_42', 'payment_intent' => 'pi_snapshot_42', 'amount_total' => 6610, 'currency' => 'eur',
            'metadata' => ['checkout_snapshot_id' => $snapshot['id'], 'transfer_group' => 'dz_cart_42'],
        ]));

        self::assertSame(42, $resolved['payload']['cart_id']);
        self::assertSame(6610, $resolved['payload']['totals']['grand_total_cents']);
        self::assertSame(7, $resolved['payload']['lines'][0]['seller_id']);
        self::assertSame(1750, $resolved['payload']['sellers'][0]['commission_basis_points']);
        self::assertSame(4918, $resolved['payload']['lines'][0]['unit_amount_tax_excl_cents']);
        self::assertSame($snapshot['id'], $session->metadata['checkout_snapshot_id']);
    }

    public function test_rejects_a_stripe_total_that_differs_from_the_frozen_quote(): void
    {
        $db = $this->database();
        $db->table('manufacturer_details')->insert(['id_manufacturer' => 7, 'commission' => '20.00']);
        $order = $this->createMock(Order::class);
        $session = OrderSession::create([
            'cart_id' => 42, 'id_customer' => 9, 'id_carrier' => 3,
            'customer' => CustomerEntity::create(['email' => 'buyer@example.test', 'firstname' => 'Buyer', 'lastname' => 'Example'], $order),
        ], $order);
        $session->addCartLineItem(['id_product' => 10, 'id_manufacturer' => 7, 'name' => 'Collare', 'price_wt' => '10.00', 'quantity' => 1]);
        $snapshot = (new CheckoutSnapshotService($db))->create($session, [
            'currency_iso' => 'EUR', 'products' => [['id_product' => 10, 'id_manufacturer' => 7, 'name' => 'Collare', 'price_wt' => '10.00', 'quantity' => 1]],
            'totals' => ['shipping_tax_incl' => '0.00'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match its immutable checkout snapshot');
        (new CheckoutSnapshotService($db))->resolveForStripeSession($snapshot['id'], \Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_wrong_total', 'amount_total' => 999, 'currency' => 'eur',
            'metadata' => ['checkout_snapshot_id' => $snapshot['id']],
        ]));
    }

    public function test_snapshot_preserves_order_total_and_verifies_only_the_stripe_residual(): void
    {
        $db = $this->database();
        $order = $this->createMock(Order::class);
        $session = OrderSession::create([
            'cart_id' => 42, 'id_customer' => 9, 'id_carrier' => 3,
            'customer' => CustomerEntity::create(['email' => 'buyer@example.test', 'firstname' => 'Buyer', 'lastname' => 'Example'], $order),
        ], $order);
        $product = ['id_product' => 10, 'id_manufacturer' => 7, 'name' => 'Collare', 'price_wt' => '75.00', 'quantity' => 1];
        $session->addCartLineItem($product);
        $session->attachGiftCardTender(GiftCardTender::fromReservation([
            'reservation' => ['id' => str_repeat('a', 32), 'card_id' => str_repeat('b', 32), 'cart_id' => 42, 'customer_id' => 9,
                'amount_cents' => 5000, 'status' => 'held', 'expires_at' => '2099-01-01T00:00:00Z'],
            'card' => ['id' => str_repeat('b', 32), 'status' => 'active', 'currency' => 'EUR'],
        ], 42, 9, 7500, time()));
        $stripePayload = $session->toStripePaymentArray();
        self::assertSame(2500, $stripePayload['line_items'][0]['price_data']['unit_amount']);
        self::assertArrayNotHasKey('gift_card', $stripePayload);
        $service = new CheckoutSnapshotService($db);
        $snapshot = $service->create($session, [
            'currency_iso' => 'EUR', 'products' => [$product], 'totals' => ['shipping_tax_incl' => '0.00'],
        ]);

        $resolved = $service->resolveForStripeSession($snapshot['id'], \Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_gift_split', 'amount_total' => 2500, 'currency' => 'eur',
            'metadata' => ['checkout_snapshot_id' => $snapshot['id']],
        ]));
        self::assertSame(7500, $resolved['payload']['totals']['grand_total_cents']);
        self::assertSame(5000, $resolved['payload']['gift_card']['gift_card_cents']);
        self::assertSame(2500, $resolved['payload']['gift_card']['stripe_cents']);
        self::assertArrayNotHasKey('code', $resolved['payload']['gift_card']);
    }

    private function database(): Manager
    {
        $db = new Manager();
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->bootEloquent();
        $db->setAsGlobal();
        $db->schema()->create('manufacturer_details', function ($table): void {
            $table->unsignedInteger('id_manufacturer')->primary();
            $table->string('commission');
        });
        $db->schema()->create('stripe_checkout_snapshots', function ($table): void {
            $table->string('id', 32)->primary();
            $table->unsignedInteger('cart_id');
            $table->string('checkout_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('transfer_group')->nullable();
            $table->string('currency', 3);
            $table->text('payload_ciphertext');
            $table->string('payload_hash', 64);
            $table->text('verification_payload');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
        return $db;
    }
}
