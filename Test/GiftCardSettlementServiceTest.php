<?php
declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Repositories\FinancialTransactionRepository;
use PS\Webservice\Service\Financial\FinancialLedgerService;
use PS\Webservice\Service\GiftCard\GiftCardClient;
use PS\Webservice\Service\GiftCard\GiftCardSettlementService;
use PS\Webservice\Service\GiftCard\GiftCardTender;
use PS\Webservice\Service\Payments\CheckoutSnapshotService;
use PS\Webservice\Service\Payments\StripeConnectService;
use PS\Webservice\Service\PS\Order;

final class GiftCardSettlementServiceTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_recovers_after_capture_failure_without_creating_a_second_order(): void
    {
        $previousFacade = Facade::getFacadeApplication();
        $previousEnv = $_ENV['APP_ENV'] ?? null;
        $previousFlow = $_ENV['STRIPE_PAYMENT_FLOW'] ?? null;
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['STRIPE_PAYMENT_FLOW'] = 'standard';
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(['cache' => new Repository(new ArrayStore())]);
        try {
            $db = new Manager(new \Illuminate\Container\Container());
            $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
            $db->setAsGlobal();
            $db->bootEloquent();
            $db->schema()->create('manufacturer_details', function ($table): void {
                $table->unsignedInteger('id_manufacturer')->primary(); $table->string('commission');
            });
            $db->schema()->create('stripe_checkout_snapshots', function ($table): void {
                $table->string('id')->primary(); $table->integer('cart_id');
                $table->string('checkout_session_id')->nullable(); $table->string('stripe_payment_intent_id')->nullable();
                $table->integer('order_id')->nullable(); $table->string('transfer_group')->nullable();
                $table->string('currency'); $table->text('payload_ciphertext'); $table->string('payload_hash');
                $table->text('verification_payload'); $table->string('gift_card_reservation_id')->nullable();
                $table->dateTime('gift_card_payment_verified_at')->nullable(); $table->dateTime('gift_card_settled_at')->nullable();
                $table->dateTime('gift_card_allocated_at')->nullable(); $table->dateTime('gift_card_next_recovery_at')->nullable();
                $table->dateTime('gift_card_released_at')->nullable();
                $table->dateTime('created_at'); $table->dateTime('updated_at');
            });
            $schema = $db->schema();
        $schema->create('financial_transactions', function ($table): void {
            $table->increments('id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('artisan_id')->nullable();
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->string('order_reference', 64)->nullable();
            $table->string('artisan_reference', 191)->nullable();
            $table->string('order_item_reference', 191)->nullable();
            $table->string('type', 32);
            $table->string('status', 16);
            $table->decimal('amount', 20, 6);
            $table->char('currency', 3);
            $table->string('provider', 64)->nullable();
            $table->string('provider_account_id', 191)->nullable();
            $table->string('provider_transaction_id', 191)->nullable();
            $table->string('provider_event_id', 191)->nullable();
            $table->string('source_event_type', 80)->nullable();
            $table->string('source_event_id', 191)->nullable();
            $table->unsignedBigInteger('related_transaction_id')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('occurred_at');
            $table->dateTime('available_at')->nullable();
            $table->dateTime('settled_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_event_id']);
        });
        $schema->create('financial_transaction_events', function ($table): void {
            $table->increments('id');
            $table->unsignedBigInteger('financial_transaction_id');
            $table->string('event_type', 32);
            $table->string('status', 16);
            $table->string('idempotency_key', 191)->unique();
            $table->string('source_event_type', 80)->nullable();
            $table->string('source_event_id', 191)->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('occurred_at');
            $table->dateTime('received_at');
            $table->dateTime('created_at');
        });

            $orders = $this->createMock(Order::class);
            $orders->expects(self::exactly(4))->method('giftCardOrderIdForCart')->willReturnOnConsecutiveCalls(null, 123, 123, 123);
            $orders->expects(self::once())->method('confirmSessionOrder');
            $orders->method('giftCardPendingStateId')->willReturn(21);
            $orders->expects(self::exactly(2))->method('settleGiftCardOrder')->willReturnCallback(function ($cart, $snapshotId) use ($db): void {
                $db->table('stripe_checkout_snapshots')->where('id', $snapshotId)->update(['gift_card_settled_at' => gmdate('Y-m-d H:i:s')]);
            });
            $reservation = str_repeat('a', 32);
            $card = str_repeat('b', 32);
            $session = OrderSession::create(['cart_id' => 42, 'id_customer' => 9, 'id_carrier' => 3,
                'payment_module' => 'webserviceapi',
                'customer' => CustomerEntity::create(['email' => 'buyer@example.test', 'firstname' => 'Buyer', 'lastname' => 'Test'], $orders)], $orders);
            $product = ['id_product' => 10, 'id_manufacturer' => 7, 'name' => 'Collare', 'price_wt' => '30.00', 'quantity' => 1];
            $session->addCartLineItem($product);
            $held = ['id' => $reservation, 'card_id' => $card, 'cart_id' => 42, 'customer_id' => 9,
                'amount_cents' => 2970, 'status' => 'held', 'expires_at' => '2099-01-01T00:00:00Z'];
            $session->attachGiftCardTender(GiftCardTender::fromReservation(['reservation' => $held,
                'card' => ['id' => $card, 'status' => 'active', 'currency' => 'EUR']], 42, 9, 3000, time()));
            $snapshots = new CheckoutSnapshotService($db);
            $snapshot = $snapshots->create($session, ['currency_iso' => 'EUR', 'products' => [$product], 'totals' => ['shipping_tax_incl' => 0]]);
            $snapshots->markGiftCardPaymentVerified($snapshot['id']);

            $protected = json_encode(['success' => true, 'data' => ['reservation' => $held]], JSON_THROW_ON_ERROR);
            $captured = json_encode(['success' => true, 'data' => ['reservation' => $held + ['order_id' => 123]]], JSON_THROW_ON_ERROR);
            $captured = str_replace('"status":"held"', '"status":"captured"', $captured);
            $history = [];
            $stack = HandlerStack::create(new MockHandler([
                new Response(200, [], $protected), new Response(503, [], '{"success":false}'),
                new Response(200, [], $protected), new Response(200, [], $captured),
                new Response(200, [], $protected), new Response(200, [], $captured),
            ]));
            $stack->push(Middleware::history($history));
            $client = new GiftCardClient(new Client(['handler' => $stack]),
                'https://shop.example/index.php?fc=module&module=mlabgiftcard&controller=api', 'gc_api_' . str_repeat('a', 64));
            $connect = new StripeConnectService($db, null, new \Stripe\StripeClient('sk_test_123'));
            $ledger = new FinancialLedgerService(new FinancialTransactionRepository($db));
            $settlement = new GiftCardSettlementService($client, $snapshots, $orders, $connect, $ledger);
            try {
                $settlement->settle($snapshot['id']);
                self::fail('Capture should fail temporarily.');
            } catch (\PS\Webservice\Service\GiftCard\GiftCardApiException $exception) {
                self::assertSame('temporarily_unavailable', $exception->errorCode);
            }
            self::assertSame(123, (int) $db->table('stripe_checkout_snapshots')->where('id', $snapshot['id'])->value('order_id'));
            self::assertSame(123, $settlement->settle($snapshot['id']));
            self::assertNotNull($db->table('stripe_checkout_snapshots')->where('id', $snapshot['id'])->value('gift_card_allocated_at'));
            self::assertSame(123, $settlement->settle($snapshot['id']));
            self::assertSame(2, $db->table('financial_transactions')->count());
            $contribution = $db->table('financial_transactions')->where('source_event_type', 'gift_card.platform_coverage')->first();
            self::assertNotNull($contribution);
            self::assertSame(-0.30, (float) $contribution->amount);
            self::assertSame(1, $db->table('financial_transaction_events')->where('idempotency_key', 'gift-card:platform-coverage:' . $reservation)->count());
            self::assertSame($history[0]['request']->getHeaderLine('Idempotency-Key'), $history[2]['request']->getHeaderLine('Idempotency-Key'));
            self::assertSame($history[1]['request']->getHeaderLine('Idempotency-Key'), $history[3]['request']->getHeaderLine('Idempotency-Key'));
        } finally {
            if ($previousEnv === null) unset($_ENV['APP_ENV']); else $_ENV['APP_ENV'] = $previousEnv;
            if ($previousFlow === null) unset($_ENV['STRIPE_PAYMENT_FLOW']); else $_ENV['STRIPE_PAYMENT_FLOW'] = $previousFlow;
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previousFacade);
        }
    }
}
