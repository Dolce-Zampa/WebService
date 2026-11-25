<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Financial\FinancialMovement;
use PS\Webservice\Repositories\FinancialTransactionRepository;
use PS\Webservice\Service\Financial\FinancialLedgerService;
use PS\Webservice\Service\Financial\FinancialAnalyticsService;
use PS\Webservice\Service\Financial\FinancialReportService;

final class FinancialReportServiceTest extends TestCase
{
    private FinancialLedgerService $ledger;
    private FinancialReportService $report;
    private FinancialTransactionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $capsule = new DB();
        // Exercise the configured PrestaShop table prefix throughout report queries.
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'ps_']);
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
        $schema->create('stripe_connect_payouts', function ($table): void {
            $table->increments('id'); $table->string('stripe_payout_id'); $table->string('stripe_account_id'); $table->unsignedBigInteger('manufacturer_id')->nullable();
            $table->string('status'); $table->integer('amount'); $table->char('currency', 3); $table->unsignedBigInteger('status_event_created');
            $table->string('status_source'); $table->dateTime('arrival_date')->nullable(); $table->string('failure_code')->nullable();
        });
        $this->repository = new FinancialTransactionRepository($capsule);
        $this->ledger = new FinancialLedgerService($this->repository);
        $this->report = new FinancialReportService($this->repository);
    }

    public function test_reports_latest_status_with_exact_decimal_aggregates_and_filters(): void
    {
        $payment = $this->record('payment', '10.100000', '2026-10-09 08:00:00', 101, 44, 'stripe');
        $this->ledger->changeStatus($payment, 'available', 'available-payment', new DateTimeImmutable('2026-10-09 09:00:00'));
        $this->record('commission', '-2.020000', '2026-10-09 10:00:00', 101, 44, 'stripe');
        $this->record('refund', '-3.000000', '2026-10-09 11:00:00', 102, 99, 'paypal');

        $result = $this->report->report([
            'from' => '2026-10-09', 'to' => '2026-10-09', 'timezone' => 'Europe/Rome',
            'artisan_id' => '44', 'provider' => 'stripe', 'page' => '1', 'per_page' => '1',
        ]);

        self::assertSame(2, $result['summary']['movement_count']);
        self::assertSame([['currency' => 'EUR', 'amount' => '8.08']], $result['summary']['net_amount_by_currency']);
        self::assertSame(1, count($result['data']));
        self::assertSame('commission', $result['data'][0]['type']);
        self::assertSame('available', $this->report->report(['status' => 'available'])['data'][0]['status']);
        self::assertTrue($result['pagination']['has_next_page']);
        self::assertSame('2026-10-08 22:00:00', $result['filters']['from_inclusive']);
        self::assertSame('2026-10-09 22:00:00', $result['filters']['to_exclusive']);
    }

    public function test_rejects_invalid_boundaries_enums_and_limits(): void
    {
        foreach ([
            ['from' => '2026-10-10', 'to' => '2026-10-09'],
            ['status' => 'unknown'],
            ['per_page' => '101'],
            ['page' => '0'],
            ['timezone' => 'Mars/Olympus'],
        ] as $input) {
            try {
                $this->report->report($input);
                self::fail('An invalid report input must be rejected.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_legacy_stripe_transfers_are_classified_and_filtered_without_rewriting_history(): void
    {
        $id = $this->ledger->record(FinancialMovement::fromArray([
            'type' => 'payout', 'status' => 'paid', 'amount' => '-60.000000', 'currency' => 'EUR',
            'occurred_at' => '2026-10-09 08:00:00', 'artisan_id' => 44, 'provider' => 'stripe', 'provider_event_id' => 'tr_old',
            'source_event_type' => 'stripe.transfer.created', 'source_event_id' => 'tr_old',
        ]), 'legacy-transfer')->transactionId;
        $this->record('payout', '-4.000000', '2026-10-09 09:00:00', 102, 44, 'manual');

        $transfers = $this->report->report(['type' => 'transfer']);
        self::assertSame(1, $transfers['summary']['movement_count']);
        self::assertSame('transfer', $transfers['data'][0]['type']);
        self::assertSame('available', $transfers['data'][0]['status']);
        self::assertSame('payout', $this->repository->findTransaction($id)->type);
        self::assertSame(1, $this->report->report(['type' => 'payout'])['summary']['movement_count']);
        self::assertSame(1, $this->report->report(['type' => 'transfer', 'status' => 'available'])['summary']['movement_count']);
        self::assertSame(0, $this->report->report(['type' => 'transfer', 'status' => 'paid'])['summary']['movement_count']);
        $analytics = (new FinancialAnalyticsService($this->repository))->overview([]);
        self::assertSame('-60', $analytics['summary_by_currency'][0]['by_type']['transfer']);
        self::assertSame('-4', $analytics['summary_by_currency'][0]['by_type']['payout']);
    }

    public function test_reports_connect_payouts_separately_without_attributing_them_to_orders_or_ledger_totals(): void
    {
        $this->record('payment', '10.000000', '2026-10-09 08:00:00', 101, 44, 'stripe');
        DB::table('stripe_connect_payouts')->insert([
            'stripe_payout_id' => 'po_1', 'stripe_account_id' => 'acct_1', 'manufacturer_id' => 44,
            'status' => 'failed', 'amount' => 800, 'currency' => 'EUR', 'status_event_created' => strtotime('2026-10-09 09:00:00 UTC'),
            'status_source' => 'webhook', 'arrival_date' => '2026-10-11 00:00:00', 'failure_code' => 'account_closed',
        ]);
        $report = $this->report->report(['artisan_id' => 44, 'status' => 'failed']);
        self::assertSame(0, $report['summary']['movement_count']);
        self::assertSame('po_1', $report['stripe_connect_payouts']['data'][0]['stripe_payout_id']);
        self::assertSame('account_closed', $report['stripe_connect_payouts']['data'][0]['failure_code']);
        self::assertSame('2026-10-11 00:00:00', $report['stripe_connect_payouts']['data'][0]['arrival_date']);
        self::assertSame([], $this->report->report(['order_id' => 101])['stripe_connect_payouts']['data']);
        self::assertSame([['currency' => 'EUR', 'amount' => '10']], $this->report->report([])['summary']['net_amount_by_currency']);
    }

    public function test_analytics_groups_exact_costs_and_internal_customer_ids(): void
    {
        $this->record('payment', '100.000000', '2026-10-09 08:00:00', 101, 44, 'stripe', 9);
        $this->record('shipping', '-8.500000', '2026-10-09 09:00:00', 101, 44, 'manual', 9);
        $this->record('provider_fee', '-3.200000', '2026-10-09 10:00:00', 101, 44, 'stripe', 9);
        $this->record('payment', '12.000000', '2026-10-09 11:00:00', 102, 44, 'paypal', 10);

        $analytics = new FinancialAnalyticsService($this->repository);
        $result = $analytics->overview(['from' => '2026-10-09', 'to' => '2026-10-09']);

        self::assertSame('112', $result['summary_by_currency'][0]['by_type']['payment']);
        self::assertSame('-8.5', $result['summary_by_currency'][0]['by_type']['shipping']);
        self::assertSame('-3.2', $result['summary_by_currency'][0]['by_type']['provider_fee']);
        self::assertSame('100.3', $result['summary_by_currency'][0]['net_amount']);
        self::assertSame(2, $result['customer_count']);
        self::assertSame(10, $result['top_customers'][0]['customer_id']);
        self::assertSame('12', $result['top_customers'][0]['net_amount_by_currency'][0]['amount']);
    }

    private function record(string $type, string $amount, string $occurredAt, int $orderId, int $artisanId, string $provider, ?int $customerId = null): int
    {
        return $this->ledger->record(FinancialMovement::fromArray([
            'type' => $type, 'status' => 'pending', 'amount' => $amount, 'currency' => 'EUR',
            'occurred_at' => $occurredAt, 'order_id' => $orderId, 'customer_id' => $customerId, 'artisan_id' => $artisanId,
            'provider' => $provider, 'provider_event_id' => $provider . '-' . $type . '-' . $orderId,
            'source_event_type' => 'test.' . $type, 'source_event_id' => 'source-' . $type . '-' . $orderId,
        ]), 'idempotency-' . $provider . '-' . $type . '-' . $orderId)->transactionId;
    }
}
