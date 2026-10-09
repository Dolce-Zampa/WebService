<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Financial\FinancialMovement;
use PS\Webservice\Domain\Financial\FinancialWeekPeriod;
use PS\Webservice\Repositories\FinancialTransactionRepository;
use PS\Webservice\Repositories\WeeklySellerSummaryDeliveryRepository;
use PS\Webservice\Service\Financial\FinancialLedgerService;
use PS\Webservice\Service\Financial\WeeklySellerFinancialSummaryService;

final class WeeklySellerFinancialSummaryTest extends TestCase
{
    private FinancialLedgerService $ledger;
    private WeeklySellerFinancialSummaryService $summaries;
    private WeeklySellerSummaryDeliveryRepository $deliveries;

    protected function setUp(): void
    {
        parent::setUp();
        $db = new DB();
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $db->setAsGlobal();
        $db->bootEloquent();
        $schema = $db->schema();
        $schema->create('financial_transactions', function ($table): void {
            $table->increments('id'); $table->unsignedBigInteger('order_id')->nullable(); $table->unsignedBigInteger('artisan_id')->nullable(); $table->unsignedBigInteger('order_item_id')->nullable();
            $table->string('order_reference')->nullable(); $table->string('artisan_reference')->nullable(); $table->string('order_item_reference')->nullable();
            $table->string('type'); $table->string('status'); $table->decimal('amount', 20, 6); $table->char('currency', 3);
            $table->string('provider')->nullable(); $table->string('provider_account_id')->nullable(); $table->string('provider_transaction_id')->nullable(); $table->string('provider_event_id')->nullable();
            $table->string('source_event_type')->nullable(); $table->string('source_event_id')->nullable(); $table->unsignedBigInteger('related_transaction_id')->nullable(); $table->json('metadata')->nullable();
            $table->dateTime('occurred_at'); $table->dateTime('available_at')->nullable(); $table->dateTime('settled_at')->nullable(); $table->timestamps();
        });
        $schema->create('financial_transaction_events', function ($table): void {
            $table->increments('id'); $table->unsignedBigInteger('financial_transaction_id'); $table->string('event_type'); $table->string('status'); $table->string('idempotency_key')->unique();
            $table->string('source_event_type')->nullable(); $table->string('source_event_id')->nullable(); $table->json('metadata')->nullable(); $table->dateTime('occurred_at'); $table->dateTime('received_at'); $table->dateTime('created_at');
        });
        $schema->create('financial_weekly_summary_deliveries', function ($table): void {
            $table->increments('id'); $table->unsignedBigInteger('artisan_id'); $table->dateTime('period_start'); $table->dateTime('period_end'); $table->string('timezone', 64); $table->string('status', 16); $table->unsignedInteger('attempts'); $table->string('failure_code', 80)->nullable(); $table->dateTime('locked_until')->nullable(); $table->dateTime('sent_at')->nullable(); $table->timestamps();
            $table->unique(['artisan_id', 'period_start', 'timezone']);
        });

        $transactions = new FinancialTransactionRepository($db);
        $this->ledger = new FinancialLedgerService($transactions);
        $this->summaries = new WeeklySellerFinancialSummaryService($transactions);
        $this->deliveries = new WeeklySellerSummaryDeliveryRepository($db);
    }

    public function test_aggregates_the_effective_statuses_for_one_seller_only_and_excludes_period_end(): void
    {
        $period = new FinancialWeekPeriod(new DateTimeImmutable('2026-10-05 00:00:00 UTC'), new DateTimeImmutable('2026-10-12 00:00:00 UTC'), new DateTimeZone('UTC'));
        $pendingPayment = $this->record('payment', 'pending', '100.000000', '2026-10-05 00:00:00', 10, 'payment');
        $this->ledger->changeStatus($pendingPayment, 'available', 'payment-available', new DateTimeImmutable('2026-10-06 12:00:00 UTC'));
        $this->record('commission', 'available', '20.000000', '2026-10-06 09:00:00', 10, 'commission');
        $this->record('refund', 'available', '-5.000000', '2026-10-07 09:00:00', 10, 'refund');
        $this->record('payout', 'paid', '60.000000', '2026-10-08 09:00:00', 10, 'payout-paid');
        $this->record('payout', 'pending', '15.000000', '2026-10-09 09:00:00', 10, 'payout-pending');
        $this->record('payment', 'available', '999.000000', '2026-10-08 09:00:00', 11, 'other-seller');
        $this->record('payment', 'available', '77.000000', '2026-10-12 00:00:00', 10, 'end-boundary');

        $summary = $this->summaries->summarize(10, $period);

        self::assertCount(1, $summary->currencies);
        $eur = $summary->currencies[0];
        self::assertSame(1, $eur->completedSales);
        self::assertSame('100.000000', $eur->grossSales);
        self::assertSame('20.000000', $eur->commissions);
        self::assertSame('75.000000', $eur->sellerDue);
        self::assertSame('5.000000', $eur->refunds);
        self::assertSame(1, $eur->completedPayouts);
        self::assertSame('60.000000', $eur->completedPayoutAmount);
        self::assertSame(1, $eur->pendingPayouts);
        self::assertSame('15.000000', $eur->pendingPayoutAmount);
    }

    public function test_no_activity_has_no_currency_rows_and_previous_week_uses_explicit_timezone(): void
    {
        $period = FinancialWeekPeriod::precedingWeek(new DateTimeImmutable('2026-10-12 10:00:00 UTC'), new DateTimeZone('Europe/Rome'));
        $summary = $this->summaries->summarize(99, $period);

        self::assertSame('2026-10-05 00:00:00', $period->startsAt->format('Y-m-d H:i:s'));
        self::assertSame('Europe/Rome', $period->timezone->getName());
        self::assertSame([], $summary->currencies);
        self::assertFalse($summary->hasActivity());
    }

    public function test_delivery_is_idempotent_and_failed_attempts_are_traceable_without_recipient_data(): void
    {
        $period = new FinancialWeekPeriod(new DateTimeImmutable('2026-10-05 00:00:00 UTC'), new DateTimeImmutable('2026-10-12 00:00:00 UTC'), new DateTimeZone('UTC'));
        $first = $this->deliveries->claim(10, $period, new DateTimeImmutable('2026-10-12 08:00:00 UTC'));
        self::assertNotNull($first);
        $this->deliveries->markFailed($first, new RuntimeException('recipient@example.test must not be persisted'), new DateTimeImmutable('2026-10-12 08:01:00 UTC'));
        $retry = $this->deliveries->claim(10, $period, new DateTimeImmutable('2026-10-12 08:02:00 UTC'));
        self::assertNotNull($retry);
        self::assertSame(2, $retry->attempt);
        $this->deliveries->markSent($retry, new DateTimeImmutable('2026-10-12 08:03:00 UTC'));
        self::assertNull($this->deliveries->claim(10, $period, new DateTimeImmutable('2026-10-12 09:00:00 UTC')));

        $row = DB::table('financial_weekly_summary_deliveries')->first();
        self::assertSame('sent', $row->status);
        self::assertSame(2, (int) $row->attempts);
        self::assertNull($row->failure_code);
        self::assertNotContains('recipient@example.test', (array) $row);
    }

    private function record(string $type, string $status, string $amount, string $occurredAt, int $artisanId, string $key): int
    {
        return $this->ledger->record(FinancialMovement::fromArray([
            'type' => $type, 'status' => $status, 'amount' => $amount, 'currency' => 'EUR', 'occurred_at' => $occurredAt,
            'order_id' => 100 + $artisanId, 'artisan_id' => $artisanId, 'order_reference' => 'ORD-' . $artisanId, 'artisan_reference' => 'ART-' . $artisanId,
        ]), $key)->transactionId;
    }
}
