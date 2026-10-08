<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;
use PS\Webservice\Http\Controller\StripeWebhookController;
use PS\Webservice\Repositories\FinancialTransactionRepository;
use PS\Webservice\Service\Financial\FinancialLedgerService;
use PS\Webservice\Service\MailerInterface;
use PS\Webservice\Service\MailjetService;
use PS\Webservice\Service\Payments\PaymentGatewayInterface;
use PS\Webservice\Service\PS\Order;
use PHPUnit\Framework\TestCase;

final class StripeFinancialWebhookLedgerTest extends TestCase
{
    private FinancialLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new DB();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $schema = $capsule->schema();

        $schema->create('financial_transactions', function ($table): void {
            $table->increments('id');
            $table->unsignedBigInteger('order_id')->nullable();
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

        $this->ledger = new FinancialLedgerService(new FinancialTransactionRepository($capsule));
    }

    public function test_checkout_pending_without_payment_intent_is_updated_by_the_same_session(): void
    {
        $controller = $this->controller();
        $controller->ingest($this->checkoutEvent(
            'evt_pending',
            'checkout.session.async_payment_pending',
            ['id' => 'cs_ledger_1', 'amount_total' => 1299, 'currency' => 'eur', 'metadata' => ['cart_id' => '42']],
        ));
        $controller->ingest($this->checkoutEvent(
            'evt_completed',
            'checkout.session.async_payment_succeeded',
            [
                'id' => 'cs_ledger_1',
                'payment_intent' => 'pi_ledger_1',
                'amount_total' => 1299,
                'currency' => 'eur',
                'payment_status' => 'paid',
                'metadata' => ['cart_id' => '42'],
            ],
        ));

        self::assertSame(1, DB::table('financial_transactions')->count());
        $movement = DB::table('financial_transactions')->first();
        self::assertSame('pending', $movement->status);
        self::assertSame('12.99', (string) $movement->amount);
        self::assertSame(['recorded', 'status_changed'], DB::table('financial_transaction_events')->orderBy('id')->pluck('event_type')->all());
        self::assertSame(['pending', 'available'], DB::table('financial_transaction_events')->orderBy('id')->pluck('status')->all());

        $completionMetadata = json_decode((string) DB::table('financial_transaction_events')->orderByDesc('id')->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('cs_ledger_1', $completionMetadata['stripe_session_id']);
        self::assertSame('pi_ledger_1', $completionMetadata['stripe_payment_intent_id']);
        self::assertArrayNotHasKey('customer_email', $completionMetadata);
    }

    public function test_replayed_webhook_does_not_append_a_second_event(): void
    {
        $controller = $this->controller();
        $event = $this->checkoutEvent(
            'evt_failed',
            'checkout.session.async_payment_failed',
            ['id' => 'cs_ledger_2', 'amount_total' => 5000, 'currency' => 'eur', 'metadata' => ['cart_id' => '42']],
        );

        $controller->ingest($event);
        $controller->ingest($event);

        self::assertSame(1, DB::table('financial_transactions')->count());
        self::assertSame(1, DB::table('financial_transaction_events')->count());
        self::assertSame('failed', DB::table('financial_transaction_events')->value('status'));
    }

    private function controller(): StripeWebhookController
    {
        return new class(
            $this->createMock(Order::class),
            $this->createMock(MailjetService::class),
            $this->createMock(PaymentGatewayInterface::class),
            $this->createMock(MailerInterface::class),
            $this->ledger,
        ) extends StripeWebhookController {
            public function __construct(Order $order, MailjetService $mailjet, PaymentGatewayInterface $payment, MailerInterface $mailer, FinancialLedgerService $ledger)
            {
                parent::__construct($order, $mailjet, $payment, $mailer, null, null, $ledger);
            }

            public function ingest(\Stripe\Event $event): void
            {
                $this->recordFinancialWebhook($event);
            }
        };
    }

    /** @param array<string, mixed> $session */
    private function checkoutEvent(string $id, string $type, array $session): \Stripe\Event
    {
        return \Stripe\Event::constructFrom([
            'id' => $id,
            'type' => $type,
            'created' => 1791446400,
            'data' => ['object' => $session],
        ]);
    }
}
