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

    public function test_completed_checkout_is_available_and_persists_only_reconciliation_metadata(): void
    {
        $this->controller()->ingest($this->checkoutEvent(
            'evt_private_completed',
            'checkout.session.completed',
            [
                'id' => 'cs_private_1',
                'payment_intent' => 'pi_private_1',
                'amount_total' => 1299,
                'currency' => 'eur',
                'payment_status' => 'paid',
                'metadata' => [
                    'cart_id' => '42',
                    'customer_email' => 'buyer@example.test',
                    'card_number' => '4242424242424242',
                    'provider_secret' => 'should-not-be-stored',
                ],
                'customer_details' => [
                    'email' => 'buyer@example.test',
                    'address' => ['line1' => 'Via privata 1'],
                ],
            ],
        ));

        $movement = DB::table('financial_transactions')->first();
        $metadata = json_decode((string) $movement->metadata, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('available', DB::table('financial_transaction_events')->value('status'));
        self::assertSame([
            'stripe_session_id' => 'cs_private_1',
            'stripe_payment_intent_id' => 'pi_private_1',
            'cart_id' => 42,
            'stripe_payment_status' => 'paid',
        ], $metadata);
    }

    public function test_completed_but_unpaid_checkout_remains_pending_in_the_ledger(): void
    {
        $this->controller()->ingest($this->checkoutEvent(
            'evt_unpaid_completed',
            'checkout.session.completed',
            [
                'id' => 'cs_unpaid_ledger_1',
                'amount_total' => 1299,
                'currency' => 'eur',
                'payment_status' => 'unpaid',
                'metadata' => ['cart_id' => '42'],
            ],
        ));

        self::assertSame('pending', DB::table('financial_transaction_events')->value('status'));
    }

    public function test_records_an_exact_expanded_stripe_fee_and_internal_customer_id_once(): void
    {
        $event = $this->stripeEvent('evt_fee_1', 'payment_intent.succeeded', [
            'id' => 'pi_fee_1', 'amount' => 1000, 'currency' => 'eur',
            'metadata' => ['id_customer' => '77'],
            'balance_transaction' => ['id' => 'txn_fee_1', 'fee' => 129, 'currency' => 'eur'],
        ]);
        $controller = $this->controller();
        $controller->ingest($event);
        $controller->ingest($event);

        $payment = DB::table('financial_transactions')->where('type', 'payment')->first();
        $fee = DB::table('financial_transactions')->where('type', 'provider_fee')->first();
        self::assertSame(2, DB::table('financial_transactions')->count());
        self::assertSame(77, (int) $payment->customer_id);
        self::assertSame('-1.29', (string) $fee->amount);
        self::assertSame('txn_fee_1', $fee->provider_transaction_id);
        self::assertSame(77, (int) $fee->customer_id);
    }

    public function test_charge_balance_transaction_is_retrieved_and_fee_is_idempotent(): void
    {
        $controller = $this->controller(\Stripe\BalanceTransaction::constructFrom([
            'id' => 'txn_retrieved', 'fee' => 129, 'currency' => 'eur',
        ]));
        $controller->ingest($this->stripeEvent('evt_payment_fee', 'payment_intent.succeeded', [
            'id' => 'pi_retrieved', 'amount' => 1000, 'currency' => 'eur',
        ]));
        $charge = $this->stripeEvent('evt_charge_fee', 'charge.succeeded', [
            'id' => 'ch_retrieved', 'payment_intent' => 'pi_retrieved', 'balance_transaction' => 'txn_retrieved',
        ]);
        $controller->ingest($charge);
        $controller->ingest($charge);
        $controller->ingest($this->stripeEvent('evt_charge_fee_updated', 'charge.updated', [
            'id' => 'ch_retrieved', 'payment_intent' => 'pi_retrieved', 'balance_transaction' => 'txn_retrieved',
        ]));

        $fee = DB::table('financial_transactions')->where('type', 'provider_fee')->first();
        self::assertSame(1, DB::table('financial_transactions')->where('type', 'provider_fee')->count());
        self::assertSame('txn_retrieved', $fee->provider_transaction_id);
        self::assertSame('EUR', $fee->currency);
        self::assertSame('-1.29', (string) $fee->amount);
    }

    public function test_charge_is_retried_if_payment_has_not_been_recorded(): void
    {
        $controller = $this->controller(\Stripe\BalanceTransaction::constructFrom([
            'id' => 'txn_out_of_order', 'fee' => 50, 'currency' => 'eur',
        ]));
        $charge = $this->stripeEvent('evt_charge_first', 'charge.succeeded', [
            'id' => 'ch_first', 'payment_intent' => 'pi_first', 'balance_transaction' => 'txn_out_of_order',
        ]);
        try {
            $controller->ingest($charge);
            self::fail('A charge without its payment must be retried.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('payment is not yet available', $exception->getMessage());
        }
        $controller->ingest($this->stripeEvent('evt_payment_later', 'payment_intent.succeeded', [
            'id' => 'pi_first', 'amount' => 1000, 'currency' => 'eur',
        ]));
        $controller->ingest($charge);
        self::assertSame(1, DB::table('financial_transactions')->where('type', 'provider_fee')->count());
    }

    public function test_balance_transaction_currency_is_used_for_fee(): void
    {
        $controller = $this->controller(\Stripe\BalanceTransaction::constructFrom([
            'id' => 'txn_other_currency', 'fee' => 123, 'currency' => 'usd',
        ]));
        $controller->ingest($this->stripeEvent('evt_payment_currency', 'payment_intent.succeeded', [
            'id' => 'pi_currency', 'amount' => 1000, 'currency' => 'eur',
        ]));
        $controller->ingest($this->stripeEvent('evt_charge_currency', 'charge.updated', [
            'id' => 'ch_currency', 'payment_intent' => 'pi_currency', 'balance_transaction' => 'txn_other_currency',
        ]));
        $fee = DB::table('financial_transactions')->where('type', 'provider_fee')->first();
        self::assertSame('USD', $fee->currency);
        self::assertSame('-1.23', (string) $fee->amount);
    }

    public function test_failed_balance_transaction_lookup_does_not_create_a_fee(): void
    {
        $controller = $this->controller();
        $controller->ingest($this->stripeEvent('evt_payment_lookup', 'payment_intent.succeeded', [
            'id' => 'pi_lookup', 'amount' => 1000, 'currency' => 'eur',
        ]));
        try {
            $controller->ingest($this->stripeEvent('evt_charge_lookup', 'charge.succeeded', [
                'id' => 'ch_lookup', 'payment_intent' => 'pi_lookup', 'balance_transaction' => 'txn_lookup',
            ]));
            self::fail('A failed lookup must cause webhook retry.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Unexpected balance transaction retrieval.', $exception->getMessage());
        }
        self::assertSame(0, DB::table('financial_transactions')->where('type', 'provider_fee')->count());
    }

    public function test_fee_with_incomplete_balance_transaction_is_retried(): void
    {
        $controller = $this->controller(\Stripe\BalanceTransaction::constructFrom([
            'id' => 'txn_incomplete', 'fee' => 50,
        ]));
        $controller->ingest($this->stripeEvent('evt_payment_incomplete', 'payment_intent.succeeded', [
            'id' => 'pi_incomplete', 'amount' => 1000, 'currency' => 'eur',
        ]));
        try {
            $controller->ingest($this->stripeEvent('evt_charge_incomplete', 'charge.updated', [
                'id' => 'ch_incomplete', 'payment_intent' => 'pi_incomplete', 'balance_transaction' => 'txn_incomplete',
            ]));
            self::fail('A balance transaction without currency must be retried.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('no valid currency', $exception->getMessage());
        }
        self::assertSame(0, DB::table('financial_transactions')->where('type', 'provider_fee')->count());
    }

    public function test_fee_for_unexpected_balance_transaction_is_retried(): void
    {
        $controller = $this->controller(\Stripe\BalanceTransaction::constructFrom([
            'id' => 'txn_other', 'fee' => 50, 'currency' => 'eur',
        ]));
        $controller->ingest($this->stripeEvent('evt_payment_other', 'payment_intent.succeeded', [
            'id' => 'pi_other', 'amount' => 1000, 'currency' => 'eur',
        ]));
        try {
            $controller->ingest($this->stripeEvent('evt_charge_other', 'charge.updated', [
                'id' => 'ch_other', 'payment_intent' => 'pi_other', 'balance_transaction' => 'txn_expected',
            ]));
            self::fail('An unexpected balance transaction must be retried.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('different balance transaction', $exception->getMessage());
        }
        self::assertSame(0, DB::table('financial_transactions')->where('type', 'provider_fee')->count());
    }

    public function test_connected_account_is_used_to_retrieve_and_record_zero_decimal_fee(): void
    {
        $controller = $this->controller(\Stripe\BalanceTransaction::constructFrom([
            'id' => 'txn_connect', 'fee' => 123, 'currency' => 'jpy',
        ]));
        $controller->ingest($this->stripeEvent('evt_payment_connect', 'payment_intent.succeeded', [
            'id' => 'pi_connect', 'amount' => 1000, 'currency' => 'jpy',
        ]));
        $event = $this->stripeEvent('evt_charge_connect', 'charge.updated', [
            'id' => 'ch_connect', 'payment_intent' => 'pi_connect', 'balance_transaction' => 'txn_connect',
        ]);
        $event->account = 'acct_test';
        $controller->ingest($event);

        self::assertSame(['txn_connect', 'acct_test'], $controller->lastRetrieval);
        $fee = DB::table('financial_transactions')->where('type', 'provider_fee')->first();
        self::assertSame('acct_test', $fee->provider_account_id);
        self::assertSame('JPY', $fee->currency);
        self::assertSame('-123', (string) $fee->amount);
    }

    public function test_missing_charge_balance_is_retrieved_or_retried(): void
    {
        $charge = \Stripe\Charge::constructFrom([
            'id' => 'ch_delayed', 'payment_intent' => 'pi_delayed', 'balance_transaction' => null,
        ]);
        $controller = $this->controller(null, $charge);
        $controller->ingest($this->stripeEvent('evt_payment_delayed', 'payment_intent.succeeded', [
            'id' => 'pi_delayed', 'amount' => 1000, 'currency' => 'eur',
        ]));
        $event = $this->stripeEvent('evt_charge_delayed', 'charge.succeeded', [
            'id' => 'ch_delayed', 'payment_intent' => 'pi_delayed', 'balance_transaction' => null,
        ]);
        try {
            $controller->ingest($event);
            self::fail('Unavailable balance transaction must be retried.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('not yet available', $exception->getMessage());
        }
        $charge->balance_transaction = ['id' => 'txn_delayed', 'fee' => 10, 'currency' => 'eur'];
        $controller->ingest($event);
        self::assertSame(1, DB::table('financial_transactions')->where('type', 'provider_fee')->count());
    }

    public function test_nonfinal_charge_update_does_not_attempt_balance_lookup(): void
    {
        $controller = $this->controller();
        $controller->ingest($this->stripeEvent('evt_charge_pending', 'charge.updated', [
            'id' => 'ch_pending', 'payment_intent' => 'pi_pending', 'status' => 'pending', 'balance_transaction' => null,
        ]));
        self::assertSame(0, DB::table('financial_transactions')->count());
    }

    public function test_fee_backfill_is_dry_run_by_default_and_does_not_invent_provider_event(): void
    {
        $controller = $this->controller();
        $controller->ingest($this->stripeEvent('evt_payment_backfill', 'payment_intent.succeeded', [
            'id' => 'pi_backfill', 'amount' => 1000, 'currency' => 'eur',
        ]));
        $charge = \Stripe\Charge::constructFrom([
            'id' => 'ch_backfill', 'status' => 'succeeded', 'payment_intent' => 'pi_backfill', 'created' => 1791446400,
            'balance_transaction' => ['id' => 'txn_backfill', 'fee' => 100, 'currency' => 'eur'],
        ]);
        self::assertSame('ready', $controller->reconcileStripeCharge($charge, null, true));
        self::assertSame(0, DB::table('financial_transactions')->where('type', 'provider_fee')->count());
        self::assertSame('recorded', $controller->reconcileStripeCharge($charge, null, false));
        $controller->ingest($this->stripeEvent('evt_charge_backfill', 'charge.succeeded', $charge->toArray()));
        self::assertSame(1, DB::table('financial_transactions')->where('type', 'provider_fee')->count());
        $fee = DB::table('financial_transactions')->where('type', 'provider_fee')->first();
        self::assertNull($fee->provider_event_id);
        self::assertSame('stripe.reconciliation.fee', $fee->source_event_type);
        self::assertSame('txn_backfill', $fee->source_event_id);
    }

    public function test_ugx_amounts_use_stripes_two_decimal_representation(): void
    {
        $controller = $this->controller();
        $controller->ingest($this->stripeEvent('evt_ugx_payment', 'payment_intent.succeeded', [
            'id' => 'pi_ugx', 'amount' => 100000, 'currency' => 'ugx',
            'balance_transaction' => ['id' => 'txn_ugx', 'fee' => 500, 'currency' => 'ugx'],
        ]));
        self::assertSame('1000', (string) DB::table('financial_transactions')->where('type', 'payment')->value('amount'));
        self::assertSame('-5', (string) DB::table('financial_transactions')->where('type', 'provider_fee')->value('amount'));
    }

    public function test_partial_and_full_refunds_are_linked_reversals_with_their_own_provider_ids(): void
    {
        $controller = $this->controller();
        $controller->ingest($this->stripeEvent('evt_payment', 'payment_intent.succeeded', [
            'id' => 'pi_refunded_1',
            'amount' => 1299,
            'currency' => 'eur',
        ]));
        $controller->ingest($this->stripeEvent('evt_refund_partial', 'refund.created', [
            'id' => 're_partial_1',
            'payment_intent' => 'pi_refunded_1',
            'amount' => 300,
            'currency' => 'eur',
            'status' => 'succeeded',
        ]));
        $controller->ingest($this->stripeEvent('evt_refund_remaining', 'refund.created', [
            'id' => 're_remaining_1',
            'payment_intent' => 'pi_refunded_1',
            'amount' => 999,
            'currency' => 'eur',
            'status' => 'succeeded',
        ]));

        $payment = DB::table('financial_transactions')->where('provider_transaction_id', 'pi_refunded_1')->first();
        $refunds = DB::table('financial_transactions')->where('type', 'refund')->orderBy('id')->get();

        self::assertSame(3, DB::table('financial_transactions')->count());
        self::assertCount(2, $refunds);
        self::assertSame(['-3', '-9.99'], $refunds->pluck('amount')->map(static fn ($amount): string => (string) $amount)->all());
        self::assertSame([(int) $payment->id, (int) $payment->id], $refunds->pluck('related_transaction_id')->map(static fn ($id): int => (int) $id)->all());
        self::assertSame(['re_partial_1', 're_remaining_1'], $refunds->pluck('provider_transaction_id')->all());
        self::assertSame(['available', 'available'], $refunds->pluck('status')->all());
        self::assertSame(2, DB::table('financial_transaction_events')->where('event_type', 'reversal')->count());
    }

    public function test_refund_updates_append_a_status_event_and_retries_are_idempotent(): void
    {
        $controller = $this->controller();
        $controller->ingest($this->stripeEvent('evt_payment_pending', 'payment_intent.processing', [
            'id' => 'pi_refund_lifecycle',
            'amount' => 500,
            'currency' => 'eur',
        ]));
        $created = $this->stripeEvent('evt_refund_pending', 'refund.created', [
            'id' => 're_lifecycle_1',
            'payment_intent' => 'pi_refund_lifecycle',
            'amount' => 500,
            'currency' => 'eur',
            'status' => 'pending',
        ]);
        $updated = $this->stripeEvent('evt_refund_succeeded', 'refund.updated', [
            'id' => 're_lifecycle_1',
            'payment_intent' => 'pi_refund_lifecycle',
            'amount' => 500,
            'currency' => 'eur',
            'status' => 'succeeded',
        ]);

        $controller->ingest($created);
        $controller->ingest($created);
        $controller->ingest($updated);
        $controller->ingest($updated);

        $refund = DB::table('financial_transactions')->where('provider_transaction_id', 're_lifecycle_1')->first();
        self::assertSame(2, DB::table('financial_transactions')->count());
        self::assertSame(['reversal', 'status_changed'], DB::table('financial_transaction_events')
            ->where('financial_transaction_id', $refund->id)
            ->orderBy('id')
            ->pluck('event_type')
            ->all());
        self::assertSame(['pending', 'available'], DB::table('financial_transaction_events')
            ->where('financial_transaction_id', $refund->id)
            ->orderBy('id')
            ->pluck('status')
            ->all());
    }

    public function test_refund_without_an_original_payment_is_not_recorded_as_an_orphan(): void
    {
        try {
            $this->controller()->ingest($this->stripeEvent('evt_orphan_refund', 'refund.created', [
                'id' => 're_orphan_1',
                'payment_intent' => 'pi_missing',
                'amount' => 100,
                'currency' => 'eur',
                'status' => 'succeeded',
            ]));
            self::fail('An orphan refund must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('Stripe refund original payment is not recorded.', $exception->getMessage());
        }

        self::assertSame(0, DB::table('financial_transactions')->count());
        self::assertSame(0, DB::table('financial_transaction_events')->count());
    }

    public function test_refund_in_a_different_currency_does_not_create_a_reversal(): void
    {
        $controller = $this->controller();
        $controller->ingest($this->stripeEvent('evt_payment_eur', 'payment_intent.succeeded', [
            'id' => 'pi_eur_only',
            'amount' => 1000,
            'currency' => 'eur',
        ]));

        try {
            $controller->ingest($this->stripeEvent('evt_refund_usd', 'refund.created', [
                'id' => 're_wrong_currency',
                'payment_intent' => 'pi_eur_only',
                'amount' => 1000,
                'currency' => 'usd',
                'status' => 'succeeded',
            ]));
            self::fail('A refund in a currency different from its payment must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('Stripe refund currency differs from the original payment.', $exception->getMessage());
        }

        self::assertSame(1, DB::table('financial_transactions')->count());
        self::assertSame(0, DB::table('financial_transactions')->where('type', 'refund')->count());
        self::assertSame(1, DB::table('financial_transaction_events')->count());
    }

    private function controller(?\Stripe\BalanceTransaction $balance = null, ?\Stripe\Charge $charge = null): StripeWebhookController
    {
        return new class(
            $this->createMock(Order::class),
            $this->createMock(MailjetService::class),
            $this->createMock(PaymentGatewayInterface::class),
            $this->createMock(MailerInterface::class),
            $this->ledger,
            $balance,
            $charge,
        ) extends StripeWebhookController {
            public ?array $lastRetrieval = null;

            public function __construct(Order $order, MailjetService $mailjet, PaymentGatewayInterface $payment, MailerInterface $mailer, FinancialLedgerService $ledger, private ?\Stripe\BalanceTransaction $balance, private ?\Stripe\Charge $charge)
            {
                parent::__construct($order, $mailjet, $payment, $mailer, null, null, $ledger);
            }

            protected function retrieveStripeCharge(string $id, ?string $accountId): \Stripe\Charge
            {
                return $this->charge ?? throw new \RuntimeException('Unexpected charge retrieval.');
            }

            protected function retrieveStripeBalanceTransaction(string $id, ?string $accountId): \Stripe\BalanceTransaction
            {
                $this->lastRetrieval = [$id, $accountId];
                return $this->balance ?? throw new \RuntimeException('Unexpected balance transaction retrieval.');
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
        return $this->stripeEvent($id, $type, $session);
    }

    /** @param array<string, mixed> $object */
    private function stripeEvent(string $id, string $type, array $object): \Stripe\Event
    {
        return \Stripe\Event::constructFrom([
            'id' => $id,
            'type' => $type,
            'created' => 1791446400,
            'data' => ['object' => $object],
        ]);
    }
}
