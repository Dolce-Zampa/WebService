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
            $table->increments('id'); $table->unsignedBigInteger('order_id')->nullable(); $table->unsignedBigInteger('customer_id')->nullable(); $table->unsignedBigInteger('artisan_id')->nullable(); $table->unsignedBigInteger('order_item_id')->nullable();
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
        $schema->create('stripe_connect_payouts', function ($table): void {
            $table->increments('id'); $table->unsignedBigInteger('manufacturer_id')->nullable(); $table->string('status');
            $table->integer('amount'); $table->char('currency', 3); $table->unsignedBigInteger('status_event_created'); $table->string('status_source')->default('webhook');
            $table->string('stripe_account_id'); $table->string('stripe_payout_id');
        });
        $schema->create('stripe_connect_payout_events', function ($table): void {
            $table->increments('id'); $table->string('stripe_account_id'); $table->string('stripe_payout_id');
            $table->string('stripe_event_id')->nullable(); $table->string('status'); $table->unsignedBigInteger('event_created');
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

    public function test_distinguishes_legacy_transfers_from_bank_payout_snapshots(): void
    {
        $period = new FinancialWeekPeriod(new DateTimeImmutable('2026-10-05 00:00:00 UTC'), new DateTimeImmutable('2026-10-12 00:00:00 UTC'), new DateTimeZone('UTC'));
        $this->ledger->record(FinancialMovement::fromArray([
            'type' => 'payout', 'status' => 'paid', 'amount' => '-60.000000', 'currency' => 'EUR', 'occurred_at' => '2026-10-08 09:00:00',
            'artisan_id' => 10, 'provider' => 'stripe', 'provider_transaction_id' => 'tr_old', 'provider_event_id' => 'tr_old', 'source_event_type' => 'stripe.transfer.created', 'source_event_id' => 'tr_old',
        ]), 'old-transfer');
        DB::table('stripe_connect_payouts')->insert([
            ['stripe_account_id' => 'acct_10', 'stripe_payout_id' => 'po_1', 'manufacturer_id' => 10, 'status' => 'paid', 'amount' => 6000, 'currency' => 'EUR', 'status_event_created' => strtotime('2026-10-09 09:00:00 UTC')],
            ['stripe_account_id' => 'acct_10', 'stripe_payout_id' => 'po_2', 'manufacturer_id' => 10, 'status' => 'in_transit', 'amount' => 1500, 'currency' => 'EUR', 'status_event_created' => strtotime('2026-10-10 09:00:00 UTC')],
            ['stripe_account_id' => 'acct_10', 'stripe_payout_id' => 'po_3', 'manufacturer_id' => 10, 'status' => 'paid', 'amount' => 500, 'currency' => 'JPY', 'status_event_created' => strtotime('2026-10-10 09:00:00 UTC')],
            ['stripe_account_id' => 'acct_11', 'stripe_payout_id' => 'po_4', 'manufacturer_id' => 11, 'status' => 'paid', 'amount' => 9000, 'currency' => 'EUR', 'status_event_created' => strtotime('2026-10-09 09:00:00 UTC')],
        ]);
        DB::table('stripe_connect_payouts')->insert(['stripe_account_id' => 'acct_10', 'stripe_payout_id' => 'po_backfill', 'manufacturer_id' => 10, 'status' => 'paid', 'amount' => 9900, 'currency' => 'EUR', 'status_event_created' => strtotime('2026-10-09 09:00:00 UTC'), 'status_source' => 'reconciliation']);
        foreach ([['acct_10', 'po_1', 'paid'], ['acct_10', 'po_2', 'pending'], ['acct_10', 'po_3', 'paid'], ['acct_11', 'po_4', 'paid']] as [$account, $payout, $status]) {
            DB::table('stripe_connect_payout_events')->insert(['stripe_account_id' => $account, 'stripe_payout_id' => $payout, 'stripe_event_id' => 'evt_' . $payout, 'status' => $status, 'event_created' => strtotime('2026-10-09 09:00:00 UTC')]);
        }

        $rows = $this->summaries->summarize(10, $period)->currencies;
        self::assertSame(1, $rows[0]->completedTransfers);
        self::assertSame('60.000000', $rows[0]->completedTransferAmount);
        self::assertSame(1, $rows[0]->completedPayouts);
        self::assertSame('60.000000', $rows[0]->completedPayoutAmount);
        self::assertSame(1, $rows[0]->pendingPayouts);
        self::assertSame('15.000000', $rows[0]->pendingPayoutAmount);
        self::assertSame('JPY', $rows[1]->currency);
        self::assertSame('500.000000', $rows[1]->completedPayoutAmount);
    }

    public function test_payout_updates_do_not_shift_its_paid_week_and_failed_payout_is_not_paid(): void
    {
        $period = new FinancialWeekPeriod(new DateTimeImmutable('2026-10-05 UTC'), new DateTimeImmutable('2026-10-12 UTC'), new DateTimeZone('UTC'));
        DB::table('stripe_connect_payouts')->insert(['stripe_account_id' => 'acct_10', 'stripe_payout_id' => 'po_1', 'manufacturer_id' => 10, 'status' => 'paid', 'amount' => 6000, 'currency' => 'EUR', 'status_event_created' => strtotime('2026-10-15 UTC')]);
        foreach (['2026-10-09 UTC', '2026-10-15 UTC'] as $index => $date) {
            DB::table('stripe_connect_payout_events')->insert(['stripe_account_id' => 'acct_10', 'stripe_payout_id' => 'po_1', 'stripe_event_id' => 'evt_' . $index, 'status' => 'paid', 'event_created' => strtotime($date)]);
        }
        self::assertSame(1, $this->summaries->summarize(10, $period)->currencies[0]->completedPayouts);
        DB::table('stripe_connect_payouts')->where('stripe_payout_id', 'po_1')->update(['status' => 'failed']);
        self::assertSame([], $this->summaries->summarize(10, $period)->currencies);
    }

    public function test_reversed_transfer_keeps_original_and_reversal_amounts_in_weekly_totals(): void
    {
        $period = new FinancialWeekPeriod(new DateTimeImmutable('2026-10-05 UTC'), new DateTimeImmutable('2026-10-12 UTC'), new DateTimeZone('UTC'));
        foreach ([['tr_old', '-60', 'stripe.transfer.created'], ['trr_old', '10', 'stripe.transfer.reversal.created']] as [$id, $amount, $event]) {
            $this->ledger->record(FinancialMovement::fromArray([
                'type' => 'payout', 'status' => 'reversed', 'amount' => $amount, 'currency' => 'EUR', 'occurred_at' => '2026-10-08 09:00:00',
                'artisan_id' => 10, 'provider' => 'stripe', 'provider_event_id' => $id, 'source_event_type' => $event, 'source_event_id' => $id,
            ]), $id);
        }
        $row = $this->summaries->summarize(10, $period)->currencies[0];
        self::assertSame(1, $row->completedTransfers);
        self::assertSame('50.000000', $row->completedTransferAmount);
        self::assertSame(0, $row->completedPayouts);
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
