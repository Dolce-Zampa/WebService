<?php
declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Entities\CartEntity;
use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Http\Controller\OrderController;
use PS\Webservice\Service\GiftCard\GiftCardCheckoutRateLimiter;
use PS\Webservice\Service\GiftCard\GiftCardClient;
use PS\Webservice\Service\Payments\CheckoutSnapshotService;
use PS\Webservice\Service\Payments\PaymentGatewayInterface;
use PS\Webservice\Service\PS\Order;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class GiftCardCheckoutControllerTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_registered_customer_cannot_redeem_using_an_unverified_identity(): void
    {
        $_ENV['GIFT_CARD_CHECKOUT_ENABLED'] = 'true';
        $_ENV['APP_DISABLE_CACHE'] = 'false';
        $orders = $this->createMock(Order::class);
        $orders->expects(self::never())->method('getCartFromId');
        $payment = $this->createMock(PaymentGatewayInterface::class);
        $payment->expects(self::never())->method('createPaymentSession');
        $controller = new OrderController($orders, $payment, null, new CheckoutSnapshotService(new Manager()),
            fn () => null, fn () => null, new GiftCardCheckoutRateLimiter());
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['id_cart' => 10, 'id_customer' => 5,
            'gift_card' => 'GC-8DUE-28DF']);
        $result = $controller->createOrder($request, $this->createMock(ResponseInterface::class), []);
        self::assertSame(403, $result->getStatusCode());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     * @dataProvider amounts
     */
    public function test_uses_stripe_only_for_the_remaining_amount(int $giftCents, int $stripeCents): void
    {
        $_ENV['GIFT_CARD_CHECKOUT_ENABLED'] = 'true';
        $_ENV['APP_DISABLE_CACHE'] = 'false';
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['PAYMENT_MODULE'] = 'webserviceapi';
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(['cache' => new Repository(new ArrayStore()), 'log' => new \Psr\Log\NullLogger()]);
        $db = new Manager(new \Illuminate\Container\Container());
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        $db->bootEloquent();
        $db->schema()->create('manufacturer_details', function ($table): void {
            $table->integer('id_manufacturer')->primary(); $table->string('commission');
        });
        $db->schema()->create('stripe_checkout_snapshots', function ($table): void {
            $table->string('id')->primary(); $table->integer('cart_id');
            $table->string('checkout_session_id')->nullable(); $table->string('stripe_payment_intent_id')->nullable();
            $table->integer('order_id')->nullable(); $table->string('transfer_group')->nullable(); $table->string('currency');
            $table->text('payload_ciphertext'); $table->string('payload_hash'); $table->text('verification_payload');
            $table->string('gift_card_reservation_id')->nullable(); $table->dateTime('gift_card_payment_verified_at')->nullable();
            $table->dateTime('gift_card_settled_at')->nullable(); $table->dateTime('gift_card_allocated_at')->nullable();
            $table->dateTime('gift_card_next_recovery_at')->nullable(); $table->dateTime('created_at'); $table->dateTime('updated_at');
            $table->dateTime('gift_card_released_at')->nullable();
        });
        $orders = $this->createMock(Order::class);
        $product = ['id_product' => 10, 'id_manufacturer' => 7, 'name' => 'Collare', 'price_wt' => '30.00', 'quantity' => 1];
        $cart = CartEntity::create(['id' => 10, 'products' => [$product], 'currency_iso' => 'EUR',
            'cart_rules' => [['code' => 'PROMO']], 'totals' => ['shipping_tax_incl' => 0, 'grand_total_tax_incl' => 25]], $orders);
        $orders->method('getCartFromId')->willReturn($cart);
        $orders->method('prepareCouponCart')->willReturn($cart);
        $orders->expects(self::never())->method('createCouponCode');
        $orders->method('giftCardOrderSummary')->willReturn(['id' => 123, 'id_cart' => 10, 'current_state' => 2, 'reference' => 'ORDER123']);
        $payment = $this->createMock(PaymentGatewayInterface::class);
        if ($stripeCents === 0) {
            $payment->expects(self::never())->method('createPaymentSession');
        } else {
            $payment->expects(self::once())->method('createPaymentSession')->willReturnCallback(function (OrderSession $session) use ($stripeCents): string {
                $stripe = $session->toStripePaymentArray();
                self::assertSame($stripeCents, $stripe['line_items'][0]['price_data']['unit_amount']);
                self::assertSame(['card'], $stripe['payment_method_types']);
                return 'https://checkout.stripe.com/c/pay/example';
            });
        }
        $reservationId = str_repeat('a', 32);
        $cardId = str_repeat('b', 32);
        $reserveBody = ['success' => true, 'data' => ['reservation' => ['id' => $reservationId, 'card_id' => $cardId,
            'cart_id' => 10, 'customer_id' => null, 'amount_cents' => $giftCents, 'status' => 'held',
            'expires_at' => '2099-01-01T00:00:00Z'], 'card' => ['id' => $cardId, 'status' => 'active', 'currency' => 'EUR']]];
        $http = new Client(['handler' => HandlerStack::create(new MockHandler([new GuzzleResponse(200, [], json_encode($reserveBody))]))]);
        $client = new GiftCardClient($http, 'https://shop.example/index.php?fc=module&module=mlabgiftcard&controller=api', 'gc_api_' . str_repeat('a', 64));
        $settlement = new class { public int $calls = 0; public function settle(string $id): int { ++$this->calls; return 123; } };
        $controller = new OrderController($orders, $payment, null, new CheckoutSnapshotService($db),
            fn () => $client, fn () => $settlement, new GiftCardCheckoutRateLimiter());
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '192.0.2.10']);
        $request->method('getParsedBody')->willReturn(['id' => 1, 'id_cart' => 10, 'id_guest' => 8, 'id_customer' => null,
            'id_carrier' => 2, 'id_lang' => 1, 'payment_module' => 'webserviceapi', 'reference' => '', 'current_state' => 0,
            'date_add' => '', 'total_paid_tax_incl' => 0, 'total_paid_tax_excl' => 0, 'payment_method' => 'stripe',
            'gift_card' => 'GC-8DUE-28DF', 'cart_rules' => [['code' => 'PROMO']],
            'customer' => ['email' => 'buyer@example.test', 'firstname' => 'Buyer', 'lastname' => 'Test']]);
        $result = $controller->createOrder($request, $this->createMock(ResponseInterface::class), []);
        self::assertSame(201, $result->getStatusCode(), (string) $result->getBody());
        $data = json_decode((string) $result->getBody(), true)['data'];
        self::assertSame($giftCents, $data['gift_card']['applied_cents']);
        self::assertSame($stripeCents, $data['gift_card']['remaining_cents']);
        self::assertSame($stripeCents === 0 ? 'confirmed' : 'payment_required', $data['checkout_status']);
        self::assertSame($stripeCents === 0 ? 1 : 0, $settlement->calls);
    }

    public static function amounts(): array { return ['fully covered' => [2500, 0], 'partially covered' => [1000, 1500]]; }
}
