<?php
declare(strict_types=1);

use GuzzleHttp\Psr7\Utils;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Http\Controller\StripeWebhookController;
use PS\Webservice\Service\GiftCard\GiftCardTender;
use PS\Webservice\Service\MailerInterface;
use PS\Webservice\Service\MailjetService;
use PS\Webservice\Service\Payments\CheckoutSnapshotService;
use PS\Webservice\Service\Payments\PaymentGatewayInterface;
use PS\Webservice\Service\PS\Order;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class GiftCardWebhookTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     * @dataProvider events
     */
    public function test_paid_or_expired_checkout_uses_the_gift_card_lifecycle(string $eventType): void
    {
        $_ENV['STRIPE_WEBHOOK_SECRET'] = 'whsec_test';
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(['log' => new \Psr\Log\NullLogger()]);
        $db = new Manager(new \Illuminate\Container\Container());
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        $db->bootEloquent();
        $db->schema()->create('manufacturer_details', function ($table): void {
            $table->integer('id_manufacturer')->primary(); $table->string('commission');
        });
        $db->schema()->create('stripe_checkout_snapshots', function ($table): void {
            $table->string('id')->primary(); $table->integer('cart_id'); $table->string('checkout_session_id')->nullable();
            $table->string('stripe_payment_intent_id')->nullable(); $table->integer('order_id')->nullable();
            $table->string('transfer_group')->nullable(); $table->string('currency'); $table->text('payload_ciphertext');
            $table->string('payload_hash'); $table->text('verification_payload'); $table->string('gift_card_reservation_id')->nullable();
            $table->dateTime('gift_card_payment_verified_at')->nullable(); $table->dateTime('gift_card_settled_at')->nullable();
            $table->dateTime('gift_card_allocated_at')->nullable(); $table->dateTime('gift_card_next_recovery_at')->nullable();
            $table->dateTime('gift_card_released_at')->nullable(); $table->dateTime('created_at'); $table->dateTime('updated_at');
        });
        $orders = $this->createMock(Order::class);
        $orders->expects(self::never())->method('confirmSessionOrder');
        $session = OrderSession::create(['cart_id' => 42, 'id_guest' => 8, 'id_carrier' => 3,
            'customer' => CustomerEntity::create(['email' => 'buyer@example.test', 'firstname' => 'Buyer', 'lastname' => 'Test'], $orders)], $orders);
        $product = ['id_product' => 10, 'id_manufacturer' => 7, 'name' => 'Collare', 'price_wt' => '25.00', 'quantity' => 1];
        $session->addCartLineItem($product);
        $reservation = str_repeat('a', 32); $card = str_repeat('b', 32);
        $session->attachGiftCardTender(GiftCardTender::fromReservation([
            'reservation' => ['id' => $reservation, 'card_id' => $card, 'cart_id' => 42, 'customer_id' => null,
                'amount_cents' => 1000, 'status' => 'held', 'expires_at' => '2099-01-01T00:00:00Z'],
            'card' => ['id' => $card, 'status' => 'active', 'currency' => 'EUR'],
        ], 42, null, 2500, time()));
        $snapshots = new CheckoutSnapshotService($db);
        $snapshot = $snapshots->create($session, ['currency_iso' => 'EUR', 'products' => [$product], 'totals' => ['shipping_tax_incl' => 0]]);
        $settlement = new class { public int $calls = 0; public function settle(string $id): int { ++$this->calls; return 123; } };
        $client = new class { public int $calls = 0; public function release(string $id, string $key): array { ++$this->calls; return []; } };
        $event = \Stripe\Event::constructFrom(['id' => 'evt_test123', 'type' => $eventType, 'data' => ['object' => [
            'object' => 'checkout.session', 'id' => 'cs_test123', 'payment_intent' => 'pi_test123',
            'payment_status' => $eventType === 'checkout.session.completed' ? 'paid' : 'unpaid',
            'amount_total' => 1500, 'currency' => 'eur', 'metadata' => ['checkout_snapshot_id' => $snapshot['id']],
        ]]]);
        $controller = new class($orders, $this->createMock(MailjetService::class), $this->createMock(PaymentGatewayInterface::class),
            $this->createMock(MailerInterface::class), $snapshots, fn () => $settlement, fn () => $client, $event) extends StripeWebhookController {
            public function __construct($orders, $mail, $payment, $mailer, $snapshots, $settlement, $client, private \Stripe\Event $event) {
                parent::__construct($orders, $mail, $payment, $mailer, null, null, null, null, null, $snapshots, $settlement, $client);
            }
            protected function constructStripeEvent(string $payload, string $sigHeader, string $secret): \Stripe\Event { return $this->event; }
        };
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getBody')->willReturn(Utils::streamFor('{}'));
        $request->method('getHeaderLine')->willReturn('signature');
        $result = $controller->handleWebhook($request, $this->createMock(ResponseInterface::class), []);
        self::assertSame(200, $result->getStatusCode(), (string) $result->getBody());
        $stored = $db->table('stripe_checkout_snapshots')->where('id', $snapshot['id'])->first();
        if ($eventType === 'checkout.session.completed') {
            self::assertSame(1, $settlement->calls); self::assertSame(0, $client->calls);
            self::assertNotNull($stored->gift_card_payment_verified_at);
        } else {
            self::assertSame(0, $settlement->calls); self::assertSame(1, $client->calls);
            self::assertNotNull($stored->gift_card_released_at);
        }
    }

    public static function events(): array { return [['checkout.session.completed'], ['checkout.session.expired']]; }
}
