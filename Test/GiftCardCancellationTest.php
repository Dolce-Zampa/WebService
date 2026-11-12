<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Http\Controller\OrderController;
use PS\Webservice\Service\GiftCard\GiftCardTender;
use PS\Webservice\Service\Payments\CheckoutPaymentAlreadyCompletedException;
use PS\Webservice\Service\Payments\CheckoutSnapshotService;
use PS\Webservice\Service\Payments\PaymentGatewayInterface;
use PS\Webservice\Service\Payments\PaymentService;
use PS\Webservice\Service\PS\Order;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class GiftCardCancellationTest extends TestCase
{
    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_cancel_expires_stripe_before_releasing_credit_and_retries_safely(): void
    {
        [$snapshots, $snapshot, $order] = $this->snapshot();
        $events = new ArrayObject();
        $payment = $this->createMock(PaymentGatewayInterface::class);
        $payment->expects(self::exactly(2))->method('expireUnpaidSession')->with('cs_test123')
            ->willReturnCallback(function () use ($events): void { $events[] = 'expire'; });
        $reservation = str_repeat('a', 32);
        $client = new class($events) {
            public function __construct(private ArrayObject $events) {}
            public function release(string $id, string $key): array {
                $this->events[] = 'release:' . $key;
                return ['reservation' => ['id' => $id, 'status' => 'released']];
            }
        };
        $controller = new OrderController($order, $payment, null, $snapshots, fn () => $client);
        $request = $this->request(['id_cart' => 42, 'id_guest' => 8, 'checkout_snapshot_id' => $snapshot['id']]);
        for ($i = 0; $i < 2; ++$i) {
            $response = $controller->cancelGiftCardCheckout($request, $this->createMock(ResponseInterface::class), []);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        }
        self::assertSame(['expire', 'release:expired:' . $reservation, 'expire', 'release:expired:' . $reservation], $events->getArrayCopy());
        self::assertTrue($snapshots->giftCardSnapshotById($snapshot['id'])['released']);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_cancel_does_not_release_a_completed_stripe_payment(): void
    {
        [$snapshots, $snapshot, $order] = $this->snapshot();
        $payment = $this->createMock(PaymentGatewayInterface::class);
        $payment->expects(self::once())->method('expireUnpaidSession')->willThrowException(new CheckoutPaymentAlreadyCompletedException());
        $controller = new OrderController($order, $payment, null, $snapshots,
            function () { self::fail('A paid session must never release its gift card.'); });
        $response = $controller->cancelGiftCardCheckout($this->request(['id_cart' => 42, 'id_guest' => 8,
            'checkout_snapshot_id' => $snapshot['id']]), $this->createMock(ResponseInterface::class), []);
        self::assertSame(409, $response->getStatusCode());
        self::assertFalse($snapshots->giftCardSnapshotById($snapshot['id'])['released']);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_cancel_keeps_the_reservation_when_stripe_cannot_be_reached(): void
    {
        [$snapshots, $snapshot, $order] = $this->snapshot();
        $payment = $this->createMock(PaymentGatewayInterface::class);
        $payment->expects(self::once())->method('expireUnpaidSession')->willThrowException(new RuntimeException('network error'));
        $controller = new OrderController($order, $payment, null, $snapshots,
            function () { self::fail('Credit must remain reserved while the session could still be paid.'); });
        $response = $controller->cancelGiftCardCheckout($this->request(['id_cart' => 42, 'id_guest' => 8,
            'checkout_snapshot_id' => $snapshot['id']]), $this->createMock(ResponseInterface::class), []);
        self::assertSame(503, $response->getStatusCode());
        self::assertFalse($snapshots->giftCardSnapshotById($snapshot['id'])['released']);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_cancel_can_retry_a_release_after_temporary_api_failure(): void
    {
        [$snapshots, $snapshot, $order] = $this->snapshot();
        $payment = $this->createMock(PaymentGatewayInterface::class);
        $payment->expects(self::exactly(2))->method('expireUnpaidSession');
        $client = new class {
            public int $calls = 0;
            public function release(string $id, string $key): array {
                if (++$this->calls === 1) throw new RuntimeException('temporary failure');
                return ['reservation' => ['id' => $id, 'status' => 'released']];
            }
        };
        $controller = new OrderController($order, $payment, null, $snapshots, fn () => $client);
        $request = $this->request(['id_cart' => 42, 'id_guest' => 8, 'checkout_snapshot_id' => $snapshot['id']]);
        self::assertSame(503, $controller->cancelGiftCardCheckout($request, $this->createMock(ResponseInterface::class), [])->getStatusCode());
        self::assertTrue($snapshots->giftCardSnapshotById($snapshot['id'])['released']);
        self::assertSame(200, $controller->cancelGiftCardCheckout($request, $this->createMock(ResponseInterface::class), [])->getStatusCode());
        self::assertSame(2, $client->calls);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_verified_gift_checkout_cannot_be_cancelled(): void
    {
        [$snapshots, $snapshot, $order] = $this->snapshot();
        $snapshots->markGiftCardPaymentVerified($snapshot['id'], \Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_test123', 'payment_status' => 'paid', 'payment_intent' => 'pi_test123',
            'amount_total' => 1500, 'currency' => 'eur', 'metadata' => ['checkout_snapshot_id' => $snapshot['id']],
        ]));
        $payment = $this->createMock(PaymentGatewayInterface::class);
        $payment->expects(self::never())->method('expireUnpaidSession');
        $controller = new OrderController($order, $payment, null, $snapshots,
            function () { self::fail('A verified payment must never be released.'); });
        $response = $controller->cancelGiftCardCheckout($this->request(['id_cart' => 42, 'id_guest' => 8,
            'checkout_snapshot_id' => $snapshot['id']]), $this->createMock(ResponseInterface::class), []);
        self::assertSame(409, $response->getStatusCode());
        self::assertFalse($snapshots->giftCardSnapshotById($snapshot['id'])['released']);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_cancel_does_not_expire_another_guests_session(): void
    {
        [$snapshots, $snapshot, $order] = $this->snapshot();
        $payment = $this->createMock(PaymentGatewayInterface::class);
        $payment->expects(self::never())->method('expireUnpaidSession');
        $controller = new OrderController($order, $payment, null, $snapshots, fn () => null);
        $response = $controller->cancelGiftCardCheckout($this->request(['id_cart' => 42, 'id_guest' => 9,
            'checkout_snapshot_id' => $snapshot['id']]), $this->createMock(ResponseInterface::class), []);
        self::assertSame(404, $response->getStatusCode());
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function test_stripe_session_expiration_refuses_a_paid_or_unconfirmed_session(): void
    {
        $gateway = new class extends PaymentService {
            public array $statuses = [];
            public int $expireCalls = 0;
            protected function retrieveCheckoutSession(string $id): \Stripe\Checkout\Session {
                return \Stripe\Checkout\Session::constructFrom(array_shift($this->statuses));
            }
            protected function expireCheckoutSession(string $id): \Stripe\Checkout\Session {
                ++$this->expireCalls;
                return \Stripe\Checkout\Session::constructFrom(array_shift($this->statuses));
            }
        };
        $gateway->statuses = [['status' => 'open', 'payment_status' => 'unpaid'], ['status' => 'expired', 'payment_status' => 'unpaid']];
        $gateway->expireUnpaidSession('cs_test123');
        self::assertSame(1, $gateway->expireCalls);
        $gateway->statuses = [['status' => 'expired', 'payment_status' => 'unpaid']];
        $gateway->expireUnpaidSession('cs_test123');
        self::assertSame(1, $gateway->expireCalls);
        $gateway->statuses = [['status' => 'complete', 'payment_status' => 'paid']];
        try { $gateway->expireUnpaidSession('cs_test123'); self::fail('Paid sessions must not expire.'); }
        catch (CheckoutPaymentAlreadyCompletedException $error) { self::assertSame(1, $gateway->expireCalls); }
        $gateway->statuses = [['status' => 'open', 'payment_status' => 'unpaid'], ['status' => 'open', 'payment_status' => 'unpaid']];
        $this->expectException(RuntimeException::class);
        $gateway->expireUnpaidSession('cs_test123');
    }

    private function snapshot(): array
    {
        $db = new Manager(new \Illuminate\Container\Container());
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal(); $db->bootEloquent();
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
        $order = $this->createMock(Order::class);
        $session = OrderSession::create(['cart_id' => 42, 'id_guest' => 8, 'id_carrier' => 3,
            'customer' => CustomerEntity::create(['email' => 'buyer@example.test', 'firstname' => 'Buyer', 'lastname' => 'Test'], $order)], $order);
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
        $snapshots->bindStripeSession($snapshot['id'], 'cs_test123');
        return [$snapshots, $snapshot, $order];
    }

    private function request(array $payload): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($payload);
        return $request;
    }
}
