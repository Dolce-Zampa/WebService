<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;
use PS\Webservice\Service\Payments\StripeConnectPayoutService;
use PHPUnit\Framework\TestCase;
use Stripe\Event;

#[\PHPUnit\Framework\Attributes\RunClassInSeparateProcess]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class StripeConnectPayoutServiceTest extends TestCase
{
    private StripeConnectPayoutService $service;

    protected function setUp(): void
    {
        $db = new DB();
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $db->setAsGlobal();
        $schema = $db->schema();
        $schema->create('stripe_connect_accounts', function ($table): void {
            $table->increments('id');
            $table->integer('manufacturer_id');
            $table->string('stripe_account_id')->unique();
        });
        $schema->create('stripe_connect_payouts', function ($table): void {
            $table->increments('id');
            $table->string('stripe_account_id');
            $table->string('stripe_payout_id');
            $table->integer('manufacturer_id')->nullable();
            $table->bigInteger('amount');
            $table->string('currency');
            $table->string('status');
            $table->string('status_source');
            $table->dateTime('arrival_date')->nullable();
            $table->string('failure_code')->nullable();
            $table->bigInteger('status_event_created');
            $table->string('status_event_id');
            $table->timestamps();
            $table->unique(['stripe_account_id', 'stripe_payout_id']);
        });
        $schema->create('stripe_connect_payout_events', function ($table): void {
            $table->increments('id');
            $table->string('stripe_account_id');
            $table->string('stripe_payout_id');
            $table->string('stripe_event_id')->nullable()->unique();
            $table->string('event_type');
            $table->string('status');
            $table->dateTime('arrival_date')->nullable();
            $table->string('failure_code')->nullable();
            $table->bigInteger('event_created');
            $table->dateTime('received_at');
        });
        DB::table('stripe_connect_accounts')->insert(['manufacturer_id' => 42, 'stripe_account_id' => 'acct_1']);
        $this->service = new StripeConnectPayoutService($db);
    }

    public function test_retries_are_idempotent_and_events_are_journaled_without_an_order(): void
    {
        $event = $this->event('evt_1', 'payout.created', 'pending', 100);
        $this->service->recordEvent($event);
        $this->service->recordEvent($event);
        $row = DB::table('stripe_connect_payouts')->first();
        self::assertSame(1, DB::table('stripe_connect_payouts')->count());
        self::assertSame(1, DB::table('stripe_connect_payout_events')->count());
        self::assertSame(42, $row->manufacturer_id);
        self::assertSame(1234, $row->amount);
        self::assertSame('EUR', $row->currency);
        self::assertSame('1970-01-01 00:05:00', $row->arrival_date);
        self::assertFalse(property_exists($row, 'order_id'));
    }

    public function test_out_of_order_events_and_failure_after_paid_are_preserved(): void
    {
        $this->service->recordEvent($this->event('evt_paid', 'payout.paid', 'paid', 200));
        $this->service->recordEvent($this->event('evt_created', 'payout.created', 'pending', 100));
        self::assertSame('paid', DB::table('stripe_connect_payouts')->value('status'));
        $this->service->recordEvent($this->event('evt_failed', 'payout.failed', 'failed', 300, ['failure_code' => 'account_closed']));
        $row = DB::table('stripe_connect_payouts')->first();
        self::assertSame('failed', $row->status);
        self::assertSame('account_closed', $row->failure_code);
        self::assertSame(3, DB::table('stripe_connect_payout_events')->count());
    }

    public function test_equal_timestamps_converge_to_terminal_status_independent_of_delivery_order(): void
    {
        $this->service->recordEvent($this->event('evt_failed', 'payout.failed', 'failed', 100));
        $this->service->recordEvent($this->event('evt_paid', 'payout.paid', 'paid', 100));
        self::assertSame('failed', DB::table('stripe_connect_payouts')->value('status'));
    }

    public function test_unknown_account_stays_unattributed_and_can_be_associated_later(): void
    {
        $this->service->recordEvent($this->event('evt_1', 'payout.created', 'pending', 100, [], 'acct_unknown'));
        self::assertNull(DB::table('stripe_connect_payouts')->value('manufacturer_id'));
        DB::table('stripe_connect_accounts')->insert(['manufacturer_id' => 99, 'stripe_account_id' => 'acct_unknown']);
        $this->service->recordEvent($this->event('evt_2', 'payout.paid', 'paid', 200, [], 'acct_unknown'));
        self::assertSame(99, DB::table('stripe_connect_payouts')->value('manufacturer_id'));
    }

    public function test_amount_mismatch_rolls_back_event(): void
    {
        $this->service->recordEvent($this->event('evt_1', 'payout.created', 'pending', 100));
        try {
            $this->service->recordEvent($this->event('evt_2', 'payout.paid', 'paid', 200, ['amount' => 999]));
            self::fail('A conflicting payout must not be accepted.');
        } catch (RuntimeException $e) {
            self::assertSame(1, DB::table('stripe_connect_payout_events')->count());
            self::assertSame('pending', DB::table('stripe_connect_payouts')->value('status'));
        }
    }

    public function test_platform_payout_without_connected_account_is_ignored(): void
    {
        $this->service->recordEvent($this->event('evt_1', 'payout.created', 'pending', 100, [], null));
        self::assertSame(0, DB::table('stripe_connect_payouts')->count());
    }

    public function test_replay_associates_account_when_onboarding_arrives_later(): void
    {
        $event = $this->event('evt_1', 'payout.created', 'pending', 100, [], 'acct_late');
        $this->service->recordEvent($event);
        DB::table('stripe_connect_accounts')->insert(['manufacturer_id' => 8, 'stripe_account_id' => 'acct_late']);
        $this->service->recordEvent($event);
        self::assertSame(8, DB::table('stripe_connect_payouts')->value('manufacturer_id'));
        self::assertSame(1, DB::table('stripe_connect_payout_events')->count());
    }

    public function test_backfill_dry_run_and_insert_are_distinct_from_webhooks(): void
    {
        $payout = \Stripe\Payout::constructFrom(['id' => 'po_history', 'amount' => 2400, 'currency' => 'eur', 'status' => 'paid', 'arrival_date' => 300]);
        self::assertTrue($this->service->recordSnapshot('acct_1', $payout, 500));
        self::assertSame(0, DB::table('stripe_connect_payouts')->count());
        self::assertTrue($this->service->recordSnapshot('acct_1', $payout, 500, false));
        self::assertFalse($this->service->recordSnapshot('acct_1', $payout, 500, false));
        self::assertSame('reconciliation', DB::table('stripe_connect_payouts')->value('status_source'));
        self::assertSame('reconciliation.snapshot', DB::table('stripe_connect_payout_events')->value('event_type'));
        self::assertNull(DB::table('stripe_connect_payout_events')->value('stripe_event_id'));
    }

    public function test_backfill_cannot_overwrite_existing_webhook_state(): void
    {
        $this->service->recordEvent($this->event('evt_failed', 'payout.failed', 'failed', 600));
        $stale = \Stripe\Payout::constructFrom(['id' => 'po_1', 'amount' => 1234, 'currency' => 'eur', 'status' => 'paid']);
        self::assertFalse($this->service->recordSnapshot('acct_1', $stale, 500, false));
        self::assertSame('failed', DB::table('stripe_connect_payouts')->value('status'));
        self::assertSame(1, DB::table('stripe_connect_payout_events')->count());
    }

    public function test_backfill_associates_unknown_account_even_when_snapshot_is_unchanged_or_newer(): void
    {
        $this->service->recordEvent($this->event('evt_1', 'payout.created', 'pending', 500, [], 'acct_late'));
        DB::table('stripe_connect_accounts')->insert(['manufacturer_id' => 9, 'stripe_account_id' => 'acct_late']);
        $snapshot = \Stripe\Payout::constructFrom(['id' => 'po_1', 'amount' => 1234, 'currency' => 'eur', 'status' => 'pending', 'arrival_date' => 300]);
        self::assertTrue($this->service->recordSnapshot('acct_late', $snapshot, 100, false));
        self::assertSame(9, DB::table('stripe_connect_payouts')->value('manufacturer_id'));
        self::assertSame('webhook', DB::table('stripe_connect_payouts')->value('status_source'));
        self::assertSame('reconciliation.account_associated', DB::table('stripe_connect_payout_events')->orderByDesc('id')->value('event_type'));
    }

    public function test_backfill_updates_a_stale_pending_snapshot_and_journals_the_observation(): void
    {
        $this->service->recordEvent($this->event('evt_created', 'payout.created', 'pending', 100));
        $current = \Stripe\Payout::constructFrom(['id' => 'po_1', 'amount' => 1234, 'currency' => 'eur', 'status' => 'paid', 'arrival_date' => 300]);
        self::assertTrue($this->service->recordSnapshot('acct_1', $current, 200, false));
        self::assertSame('paid', DB::table('stripe_connect_payouts')->value('status'));
        self::assertSame('reconciliation', DB::table('stripe_connect_payouts')->value('status_source'));
        self::assertSame(2, DB::table('stripe_connect_payout_events')->count());
        self::assertFalse($this->service->recordSnapshot('acct_1', $current, 201, false));
        self::assertSame(2, DB::table('stripe_connect_payout_events')->count());
    }

    public function test_webhook_newer_than_backfill_replaces_observation(): void
    {
        $snapshot = \Stripe\Payout::constructFrom(['id' => 'po_1', 'amount' => 1234, 'currency' => 'eur', 'status' => 'pending']);
        $this->service->recordSnapshot('acct_1', $snapshot, 100, false);
        $this->service->recordEvent($this->event('evt_paid', 'payout.paid', 'paid', 200));
        self::assertSame('paid', DB::table('stripe_connect_payouts')->value('status'));
        self::assertSame('webhook', DB::table('stripe_connect_payouts')->value('status_source'));
    }

    public function test_backfill_command_defaults_to_dry_run_and_processes_all_pages(): void
    {
        $listedAccount = null;
        $list = static function (string $account) use (&$listedAccount): iterable {
            $listedAccount = $account;
            yield \Stripe\Payout::constructFrom(['id' => 'po_one', 'amount' => 100, 'currency' => 'eur', 'status' => 'paid']);
            yield \Stripe\Payout::constructFrom(['id' => 'po_two', 'amount' => 200, 'currency' => 'eur', 'status' => 'pending']);
        };
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \PS\Webservice\Commands\BackfillStripeConnectPayoutsCommand($this->service, $list));
        self::assertSame(0, $tester->execute(['--account' => 'acct_1']));
        self::assertSame('acct_1', $listedAccount);
        self::assertSame(0, DB::table('stripe_connect_payouts')->count());
        self::assertSame(0, $tester->execute(['--account' => 'acct_1', '--apply' => true]));
        self::assertSame(2, DB::table('stripe_connect_payouts')->count());
        self::assertSame(2, DB::table('stripe_connect_payout_events')->count());
    }

    public function test_backfill_command_failure_keeps_completed_work_and_can_retry(): void
    {
        $first = \Stripe\Payout::constructFrom(['id' => 'po_one', 'amount' => 100, 'currency' => 'eur', 'status' => 'paid']);
        $second = \Stripe\Payout::constructFrom(['id' => 'po_two', 'amount' => 200, 'currency' => 'eur', 'status' => 'pending']);
        $failing = static function (string $account) use ($first): iterable {
            yield $first;
            throw new RuntimeException('Stripe pagination failed');
        };
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \PS\Webservice\Commands\BackfillStripeConnectPayoutsCommand($this->service, $failing));
        self::assertSame(1, $tester->execute(['--account' => 'acct_1', '--apply' => true]));
        self::assertSame(1, DB::table('stripe_connect_payouts')->count());
        $retry = new \Symfony\Component\Console\Tester\CommandTester(new \PS\Webservice\Commands\BackfillStripeConnectPayoutsCommand($this->service, static fn (string $account): iterable => [$first, $second]));
        self::assertSame(0, $retry->execute(['--account' => 'acct_1', '--apply' => true]));
        self::assertSame(2, DB::table('stripe_connect_payouts')->count());
        self::assertSame(2, DB::table('stripe_connect_payout_events')->count());
    }

    public function test_backfill_command_rejects_unknown_account_before_fetching(): void
    {
        $called = false;
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \PS\Webservice\Commands\BackfillStripeConnectPayoutsCommand($this->service, static function () use (&$called): iterable { $called = true; return []; }));
        self::assertSame(1, $tester->execute(['--account' => 'acct_unknown', '--apply' => true]));
        self::assertFalse($called);
    }

    private function event(string $id, string $type, string $status, int $created, array $extra = [], ?string $account = 'acct_1'): Event
    {
        return Event::constructFrom([
            'id' => $id, 'type' => $type, 'created' => $created, 'account' => $account,
            'data' => ['object' => array_merge([
                'object' => 'payout', 'id' => 'po_1', 'amount' => 1234,
                'currency' => 'eur', 'status' => $status, 'arrival_date' => 300,
            ], $extra)],
        ]);
    }
}
