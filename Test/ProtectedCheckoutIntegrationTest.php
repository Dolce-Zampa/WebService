<?php
declare(strict_types=1);

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Cache\TaggedCache;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\Facade;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Entities\CartEntity;
use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Http\Controller\OrderController;
use PS\Webservice\Service\Payments\CheckoutSnapshotService;
use PS\Webservice\Service\Payments\PaymentGatewayInterface;
use PS\Webservice\Service\GiftCard\GiftCardClient;
use PS\Webservice\Service\GiftCard\GiftCardCheckoutRateLimiter;
use PS\Webservice\Service\PS\Order;
use PS\Webservice\Service\PurchaseProtection\CartPaymentGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ProtectedCheckoutIntegrationTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     * @dataProvider quoteCases
     */
    public function test_service_line_and_confirmed_price_reach_the_payment_snapshot(int $expectedPrice, int $expectedStatus, int $giftCents, bool $moduleAvailable = true, bool $pendingMutation = false): void
    {
        foreach (['APP_KEY' => 'testing', 'PAYMENT_MODULE' => 'webserviceapi', 'APP_DISABLE_CACHE' => 'false',
            'PURCHASE_PROTECTION_CHECKOUT_ENABLED' => 'true', 'PURCHASE_PROTECTION_PRODUCT_ID' => '277',
            'PURCHASE_PROTECTION_PERCENT' => '5', 'STRIPE_CONNECT_ENABLED' => 'false',
            'GIFT_CARD_CHECKOUT_ENABLED' => 'true', 'APP_ENV' => 'testing'] as $name => $value) {
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        $lock = $this->createMock(Lock::class);
        $lock->method('get')->willReturn(true);
        $lock->method('release')->willReturn(true);
        $store = $this->createMock(RedisStore::class);
        $store->method('lock')->willReturn($lock);
        $cache = $this->createMock(Repository::class);
        $tagged = $this->createMock(TaggedCache::class);
        $cache->method('getStore')->willReturn($store);
        $cache->method('tags')->willReturn($tagged);
        $cache->method('add')->willReturn(true);
        $cache->method('increment')->willReturn(1);
        $tagged->method('tags')->willReturn($tagged);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(['cache' => $cache, 'log' => new \Psr\Log\NullLogger()]);

        $db = new Manager(new \Illuminate\Container\Container());
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        $db->bootEloquent();
        $db->schema()->create('manufacturer_details', function ($table): void {
            $table->integer('id_manufacturer')->primary(); $table->string('commission');
        });
        $db->schema()->create('protection_cart_mutations', function ($table): void {
            $table->integer('cart_id')->primary(); $table->string('operation_id');
            $table->string('operation'); $table->dateTime('created_at');
        });
        $db->schema()->create('stripe_checkout_snapshots', function ($table): void {
            $table->string('id')->primary(); $table->integer('cart_id'); $table->string('checkout_session_id')->nullable();
            $table->string('stripe_payment_intent_id')->nullable(); $table->integer('order_id')->nullable();
            $table->string('transfer_group')->nullable(); $table->string('currency'); $table->text('payload_ciphertext');
            $table->string('payload_hash'); $table->text('verification_payload'); $table->dateTime('created_at');
            $table->dateTime('updated_at'); $table->string('gift_card_reservation_id')->nullable();
            $table->dateTime('gift_card_payment_verified_at')->nullable(); $table->dateTime('gift_card_released_at')->nullable();
            $table->dateTime('gift_card_settled_at')->nullable();
        });
        if ($pendingMutation) {
            $db->table('protection_cart_mutations')->insert(['cart_id' => 42, 'operation_id' => str_repeat('a', 32),
                'operation' => 'add_protection', 'created_at' => gmdate('Y-m-d H:i:s')]);
        }
        $order = $this->createMock(Order::class);
        $order->method('purchaseProtectionAvailable')->with(277)->willReturn($moduleAvailable);
        $goods = ['id_product' => 10, 'id_manufacturer' => 7, 'name' => 'Collare', 'price_wt' => 80,
            'quantity' => 1, 'is_virtual' => false];
        $before = ['id' => 42, 'id_carrier' => 3, 'currency_iso' => 'EUR', 'products' => [$goods],
            'totals' => ['shipping_tax_incl' => 6.90, 'grand_total_tax_incl' => 86.90]];
        $after = $before;
        $after['products'][] = ['id_product' => 277, 'id_manufacturer' => 0, 'name' => 'Protezione',
            'quantity' => 1, 'price_wt' => 4, 'is_virtual' => true];
        $after['totals']['grand_total_tax_incl'] = 90.90;
        $beforeEntity = CartEntity::create($before, $order);
        $afterEntity = CartEntity::create($after, $order);
        $order->method('getCartFromId')->willReturn($beforeEntity);
        $order->method('prepareCouponCart')->willReturn($beforeEntity);
        $order->expects($expectedStatus === 201 ? self::once() : self::never())->method('setPurchaseProtection')
            ->with(42, null, 8, 277, 400)->willReturn($afterEntity);
        $payments = $this->createMock(PaymentGatewayInterface::class);
        $payments->expects($expectedStatus === 201 ? self::once() : self::never())->method('createPaymentSession')->willReturnCallback(function (OrderSession $session) use ($giftCents): string {
            self::assertSame('277', $session->metadata['protection_product_id']);
            self::assertSame('400', $session->metadata['protection_price_cents']);
            self::assertSame(90.90, $session->payableTotal());
            if ($giftCents > 0) self::assertSame(9090 - $giftCents, $session->toStripePaymentArray()['line_items'][0]['price_data']['unit_amount']);
            return 'https://checkout.example.test/protected';
        });
        $request = $this->createMock(ServerRequestInterface::class);
        $body = ['id' => 1, 'id_cart' => 42, 'id_customer' => null, 'id_guest' => 8, 'id_carrier' => 3,
            'id_lang' => 1, 'reference' => '', 'current_state' => 0, 'date_add' => '',
            'total_paid_tax_incl' => 0, 'total_paid_tax_excl' => 0,
            'delivery_address' => ['id_country' => 10], 'invoice_address' => ['id_country' => 10],
            'payment_module' => 'webserviceapi', 'purchase_protection' => true,
            'purchase_protection_expected_cents' => $expectedPrice,
            'customer' => ['email' => 'buyer@example.test', 'firstname' => 'Test', 'lastname' => 'Buyer']];
        if ($giftCents > 0) {
            $body['gift_card'] = 'GC-8DUE-28DF';
            $body['gift_card_expected'] = ['total_cents' => 9090, 'applied_cents' => $giftCents];
        }
        $request->method('getParsedBody')->willReturn($body);
        $reservation = ['success' => true, 'data' => ['reservation' => ['id' => str_repeat('a', 32),
            'card_id' => str_repeat('b', 32), 'cart_id' => 42, 'customer_id' => null,
            'amount_cents' => $giftCents, 'status' => 'held', 'expires_at' => '2099-01-01T00:00:00Z'],
            'card' => ['id' => str_repeat('b', 32), 'status' => 'active', 'currency' => 'EUR']]];
        $http = new Client(['handler' => HandlerStack::create(new MockHandler([new GuzzleResponse(200, [], json_encode($reservation))]))]);
        $giftClient = new GiftCardClient($http, 'https://shop.example/index.php?fc=module&module=mlabgiftcard&controller=api', 'gc_api_' . str_repeat('a', 64));
        $controller = new OrderController($order, $payments, null, new CheckoutSnapshotService($db), fn () => $giftClient, fn () => null,
            new GiftCardCheckoutRateLimiter(), null,
            new CartPaymentGuard($db, $payments));
        $preview = $controller->previewPurchaseProtection($request, $this->createMock(ResponseInterface::class), []);
        self::assertSame(200, $preview->getStatusCode());
        self::assertSame($moduleAvailable && !$pendingMutation, json_decode((string) $preview->getBody(), true)['data']['checkout_available']);
        $response = $controller->createOrder($request, $this->createMock(ResponseInterface::class), []);
        self::assertSame($expectedStatus, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($pendingMutation ? 1 : 0, $db->table('protection_cart_mutations')->count());
        if (!$moduleAvailable || $pendingMutation) {
            self::assertSame(0, $db->table('stripe_checkout_snapshots')->count());
            return;
        }
        if ($expectedStatus === 409) {
            $data = json_decode((string) $response->getBody(), true)['data'];
            self::assertSame('purchase_protection_changed', $data['error']['code']);
            self::assertSame(400, $data['actual_price_cents']);
            self::assertSame(0, $db->table('stripe_checkout_snapshots')->count());
            return;
        }
        $verification = json_decode((string) $db->table('stripe_checkout_snapshots')->value('verification_payload'), true);
        self::assertSame(9090, $verification['totals']['grand_total_cents']);
        self::assertSame(400, $verification['totals']['protection_cents']);
        if ($giftCents > 0) self::assertSame($giftCents, $verification['gift_card']['gift_card_cents']);
    }

    public static function quoteCases(): array
    {
        return ['accepted quote' => [400, 201, 0], 'changed quote requires consent' => [399, 409, 0],
            'partial gift card includes protection' => [400, 201, 3000],
            'unavailable PrestaShop module stops before mutation' => [400, 503, 0, false],
            'pending cart mutation prevents checkout' => [400, 409, 0, true, true]];
    }
}
