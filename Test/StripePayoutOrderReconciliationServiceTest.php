<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\Payments\StripePayoutOrderReconciliationService;
use PS\Webservice\Commands\ReconcileStripePayoutOrdersCommand;
use Symfony\Component\Console\Tester\CommandTester;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class StripePayoutOrderReconciliationServiceTest extends TestCase
{
    private DB $db;
    private object $payout;
    private array $transactions;
    private array $transfers;

    protected function setUp(): void
    {
        $this->db = new DB();
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->db->setAsGlobal();
        $schema = $this->db->schema();
        $schema->create('stripe_connect_payouts', static function ($table): void {
            $table->increments('id'); $table->string('stripe_account_id'); $table->string('stripe_payout_id');
            $table->integer('manufacturer_id')->nullable(); $table->integer('amount'); $table->string('currency'); $table->string('status');
        });
        $schema->create('stripe_connect_transfers', static function ($table): void {
            $table->increments('id'); $table->string('stripe_account_id'); $table->string('stripe_transfer_id');
            $table->integer('manufacturer_id'); $table->integer('order_id'); $table->integer('amount'); $table->string('currency'); $table->string('status');
        });
        $schema->create('stripe_connect_transfer_reversals', static function ($table): void { $table->increments('id'); $table->integer('stripe_connect_transfer_id'); });
        $schema->create('stripe_connect_payout_manual_allocations', static function ($table): void {
            $table->increments('id'); $table->string('stripe_account_id'); $table->string('stripe_payout_id'); $table->string('stripe_transfer_id'); $table->integer('direction'); $table->integer('amount');
        });
        $schema->create('stripe_connect_payout_allocations', static function ($table): void {
            $table->increments('id'); $table->string('stripe_account_id'); $table->string('stripe_payout_id');
            $table->string('stripe_balance_transaction_id'); $table->string('stripe_transfer_id'); $table->string('destination_payment_id');
            $table->integer('order_id'); $table->integer('manufacturer_id'); $table->integer('amount'); $table->string('currency'); $table->dateTime('created_at');
            $table->unique(['stripe_account_id', 'stripe_payout_id', 'stripe_balance_transaction_id']);
        });
        DB::table('stripe_connect_payouts')->insert(['stripe_account_id' => 'acct_1', 'stripe_payout_id' => 'po_1', 'manufacturer_id' => 4, 'amount' => 300, 'currency' => 'EUR', 'status' => 'paid']);
        foreach ([['tr_1', 1, 100], ['tr_2', 2, 200]] as [$id, $order, $amount]) {
            DB::table('stripe_connect_transfers')->insert(['stripe_account_id' => 'acct_1', 'stripe_transfer_id' => $id, 'manufacturer_id' => 4, 'order_id' => $order, 'amount' => $amount, 'currency' => 'EUR', 'status' => 'paid']);
        }
        $this->payout = (object) ['id' => 'po_1', 'status' => 'paid', 'amount' => 300, 'currency' => 'eur', 'automatic' => true, 'reconciliation_status' => 'completed'];
        $this->transactions = [
            (object) ['id' => 'txn_1', 'source' => 'py_1', 'type' => 'payment', 'amount' => 100, 'fee' => 0, 'net' => 100, 'currency' => 'eur'],
            (object) ['id' => 'txn_2', 'source' => 'py_2', 'type' => 'payment', 'amount' => 200, 'fee' => 0, 'net' => 200, 'currency' => 'eur'],
        ];
        $this->transfers = [
            'tr_1' => (object) ['id' => 'tr_1', 'destination' => 'acct_1', 'destination_payment' => 'py_1', 'amount' => 100, 'amount_reversed' => 0, 'currency' => 'eur'],
            'tr_2' => (object) ['id' => 'tr_2', 'destination' => 'acct_1', 'destination_payment' => 'py_2', 'amount' => 200, 'amount_reversed' => 0, 'currency' => 'eur'],
        ];
    }

    public function test_complete_payout_is_allocated_once_using_proven_balance_transactions(): void
    {
        $service = $this->service();
        self::assertSame(['status' => 'preview', 'allocations' => 2], $service->reconcile('acct_1', 'po_1'));
        self::assertSame(0, DB::table('stripe_connect_payout_allocations')->count());
        self::assertSame(['status' => 'recorded', 'allocations' => 2], $service->reconcile('acct_1', 'po_1', false));
        self::assertSame(['status' => 'already_recorded', 'allocations' => 2], $service->reconcile('acct_1', 'po_1', false));
        self::assertSame([1, 2], DB::table('stripe_connect_payout_allocations')->orderBy('order_id')->pluck('order_id')->all());
    }

    public function test_manual_and_unready_payouts_are_not_allocated(): void
    {
        $this->payout->automatic = false;
        self::assertSame('manual', $this->service()->reconcile('acct_1', 'po_1', false)['status']);
        $this->payout->automatic = true; $this->payout->reconciliation_status = 'in_progress';
        self::assertSame('not_ready', $this->service()->reconcile('acct_1', 'po_1', false)['status']);
        self::assertSame(0, DB::table('stripe_connect_payout_allocations')->count());
    }

    public function test_fees_unmatched_sources_and_reversals_do_not_create_partial_allocation(): void
    {
        $this->transactions[0]->fee = 1;
        self::assertSame('unattributed', $this->service()->reconcile('acct_1', 'po_1', false)['status']);
        $this->transactions[0]->fee = 0;
        $this->transactions[1]->source = 'py_unknown';
        self::assertSame('unattributed', $this->service()->reconcile('acct_1', 'po_1', false)['status']);
        $this->transactions[1]->source = 'py_2'; $this->transfers['tr_2']->amount_reversed = 1;
        self::assertSame('unattributed', $this->service()->reconcile('acct_1', 'po_1', false)['status']);
        self::assertSame(0, DB::table('stripe_connect_payout_allocations')->count());
    }

    public function test_manual_allocation_blocks_automatic_and_local_failure_blocks_it(): void
    {
        DB::table('stripe_connect_payout_manual_allocations')->insert(['stripe_account_id' => 'acct_1', 'stripe_payout_id' => 'po_other', 'stripe_transfer_id' => 'tr_1', 'direction' => 1, 'amount' => 100]);
        self::assertSame('unattributed', $this->service()->reconcile('acct_1', 'po_1', false)['status']);
        DB::table('stripe_connect_payouts')->update(['status' => 'failed']);
        self::assertSame('unavailable', $this->service()->reconcile('acct_1', 'po_1', false)['status']);
        self::assertSame(0, DB::table('stripe_connect_payout_allocations')->count());
    }

    public function test_failed_payout_does_not_block_a_new_payout_for_the_same_transfer(): void
    {
        DB::table('stripe_connect_payouts')->insert(['stripe_account_id' => 'acct_1', 'stripe_payout_id' => 'po_old', 'manufacturer_id' => 4, 'amount' => 100, 'currency' => 'EUR', 'status' => 'failed']);
        DB::table('stripe_connect_payout_manual_allocations')->insert(['stripe_account_id' => 'acct_1', 'stripe_payout_id' => 'po_old', 'stripe_transfer_id' => 'tr_1', 'direction' => 1, 'amount' => 100]);
        self::assertSame('recorded', $this->service()->reconcile('acct_1', 'po_1', false)['status']);
        self::assertSame(2, DB::table('stripe_connect_payout_allocations')->count());
    }

    public function test_failed_automatic_allocation_can_be_reattributed_on_another_payout(): void
    {
        DB::table('stripe_connect_payouts')->insert(['stripe_account_id' => 'acct_1', 'stripe_payout_id' => 'po_old', 'manufacturer_id' => 4, 'amount' => 100, 'currency' => 'EUR', 'status' => 'failed']);
        DB::table('stripe_connect_payout_allocations')->insert(['stripe_account_id' => 'acct_1', 'stripe_payout_id' => 'po_old', 'stripe_balance_transaction_id' => 'txn_1', 'stripe_transfer_id' => 'tr_1', 'destination_payment_id' => 'py_1', 'order_id' => 1, 'manufacturer_id' => 4, 'amount' => 100, 'currency' => 'EUR', 'created_at' => '2020-01-01 00:00:00']);
        self::assertSame('recorded', $this->service()->reconcile('acct_1', 'po_1', false)['status']);
        self::assertSame(3, DB::table('stripe_connect_payout_allocations')->count());
    }

    public function test_active_prior_allocation_blocks_retry_until_failed(): void
    {
        DB::table('stripe_connect_payouts')->insert(['stripe_account_id' => 'acct_1', 'stripe_payout_id' => 'po_old', 'manufacturer_id' => 4, 'amount' => 100, 'currency' => 'EUR', 'status' => 'pending']);
        DB::table('stripe_connect_payout_allocations')->insert(['stripe_account_id' => 'acct_1', 'stripe_payout_id' => 'po_old', 'stripe_balance_transaction_id' => 'txn_1', 'stripe_transfer_id' => 'tr_1', 'destination_payment_id' => 'py_1', 'order_id' => 1, 'manufacturer_id' => 4, 'amount' => 100, 'currency' => 'EUR', 'created_at' => '2020-01-01 00:00:00']);
        self::assertSame('unattributed', $this->service()->reconcile('acct_1', 'po_1', false)['status']);
        self::assertSame(1, DB::table('stripe_connect_payout_allocations')->count());
        DB::table('stripe_connect_payouts')->where('stripe_payout_id', 'po_old')->update(['status' => 'failed']);
        self::assertSame('recorded', $this->service()->reconcile('acct_1', 'po_1', false)['status']);
    }

    public function test_locally_pending_reversal_prevents_allocation(): void
    {
        DB::table('stripe_connect_transfer_reversals')->insert(['stripe_connect_transfer_id' => 1]);
        self::assertSame('unattributed', $this->service()->reconcile('acct_1', 'po_1', false)['status']);
        self::assertSame(0, DB::table('stripe_connect_payout_allocations')->count());
    }

    public function test_fully_reversed_manual_allocation_no_longer_blocks_automatic(): void
    {
        DB::table('stripe_connect_payout_manual_allocations')->insert([
            ['stripe_account_id' => 'acct_1', 'stripe_payout_id' => 'po_1', 'stripe_transfer_id' => 'tr_1', 'direction' => 1, 'amount' => 100],
            ['stripe_account_id' => 'acct_1', 'stripe_payout_id' => 'po_1', 'stripe_transfer_id' => 'tr_1', 'direction' => -1, 'amount' => 100],
        ]);
        self::assertSame('recorded', $this->service()->reconcile('acct_1', 'po_1', false)['status']);
        self::assertSame(2, DB::table('stripe_connect_payout_allocations')->count());
    }

    public function test_command_returns_failure_when_no_attribution_is_possible(): void
    {
        $this->payout->automatic = false;
        $tester = new CommandTester(new ReconcileStripePayoutOrdersCommand($this->service()));
        self::assertSame(1, $tester->execute(['--account' => 'acct_1', '--payout' => 'po_1']));
    }

    public function test_pagination_failure_never_partially_writes_and_command_defaults_to_dry_run(): void
    {
        $broken = $this->service(static function (): iterable { yield (object) ['id' => 'txn_1', 'source' => 'py_1', 'type' => 'payment', 'amount' => 100, 'fee' => 0, 'net' => 100, 'currency' => 'eur']; throw new RuntimeException('page error'); });
        $tester = new CommandTester(new ReconcileStripePayoutOrdersCommand($broken));
        self::assertSame(1, $tester->execute(['--account' => 'acct_1', '--payout' => 'po_1', '--apply' => true]));
        self::assertSame(0, DB::table('stripe_connect_payout_allocations')->count());
        $tester = new CommandTester(new ReconcileStripePayoutOrdersCommand($this->service()));
        self::assertSame(0, $tester->execute(['--account' => 'acct_1', '--payout' => 'po_1']));
        self::assertSame(0, DB::table('stripe_connect_payout_allocations')->count());
    }

    private function service(?Closure $list = null): StripePayoutOrderReconciliationService
    {
        return new StripePayoutOrderReconciliationService($this->db,
            fn (string $account, string $id): object => $this->payout,
            $list ?? fn (string $account, string $id): iterable => $this->transactions,
            fn (string $id): object => $this->transfers[$id]);
    }
}
