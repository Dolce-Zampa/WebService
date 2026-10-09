<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Financial\FinancialMovement;
use PS\Webservice\Repositories\FinancialTransactionRepository;
use PS\Webservice\Service\Financial\FinancialLedgerService;
use PS\Webservice\Service\Financial\FinancialReportService;

final class FinancialReportServiceTest extends TestCase
{
    private FinancialLedgerService $ledger;
    private FinancialReportService $report;

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
        $repository = new FinancialTransactionRepository($capsule);
        $this->ledger = new FinancialLedgerService($repository);
        $this->report = new FinancialReportService($repository);
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

    private function record(string $type, string $amount, string $occurredAt, int $orderId, int $artisanId, string $provider): int
    {
        return $this->ledger->record(FinancialMovement::fromArray([
            'type' => $type, 'status' => 'pending', 'amount' => $amount, 'currency' => 'EUR',
            'occurred_at' => $occurredAt, 'order_id' => $orderId, 'artisan_id' => $artisanId,
            'provider' => $provider, 'provider_event_id' => $provider . '-' . $type . '-' . $orderId,
            'source_event_type' => 'test.' . $type, 'source_event_id' => 'source-' . $type . '-' . $orderId,
        ]), 'idempotency-' . $provider . '-' . $type . '-' . $orderId)->transactionId;
    }
}
