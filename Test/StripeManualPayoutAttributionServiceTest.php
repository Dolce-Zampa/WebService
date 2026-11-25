<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;
use PS\Webservice\Service\Payments\StripeManualPayoutAttributionService;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\RunClassInSeparateProcess]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class StripeManualPayoutAttributionServiceTest extends TestCase
{
    private StripeManualPayoutAttributionService $service;

    protected function setUp(): void
    {
        $db = new DB();
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $db->setAsGlobal();
        $schema = $db->schema();
        $schema->create('stripe_connect_payouts', function ($t): void {
            $t->increments('id'); $t->string('stripe_account_id'); $t->string('stripe_payout_id');
            $t->integer('manufacturer_id')->nullable(); $t->integer('amount'); $t->string('currency'); $t->string('status');
        });
        $schema->create('stripe_connect_transfers', function ($t): void {
            $t->increments('id'); $t->string('stripe_account_id'); $t->string('stripe_transfer_id');
            $t->integer('order_id'); $t->integer('manufacturer_id'); $t->integer('amount'); $t->string('currency'); $t->string('status');
        });
        $schema->create('stripe_connect_transfer_reversals', function ($t): void { $t->increments('id'); $t->integer('stripe_connect_transfer_id'); });
        $schema->create('stripe_connect_payout_allocations', function ($t): void {
            $t->increments('id'); $t->string('stripe_account_id'); $t->string('stripe_payout_id'); $t->string('stripe_transfer_id'); $t->integer('amount');
        });
        $schema->create('stripe_connect_payout_manual_allocations', function ($t): void {
            $t->increments('id'); $t->string('stripe_account_id'); $t->string('stripe_payout_id'); $t->string('stripe_transfer_id');
            $t->integer('order_id'); $t->integer('manufacturer_id'); $t->integer('amount'); $t->integer('direction');
            $t->integer('reverses_allocation_id')->nullable()->unique(); $t->string('currency'); $t->string('idempotency_key')->unique();
            $t->string('reason'); $t->string('evidence_reference'); $t->string('operator'); $t->dateTime('created_at');
        });
        DB::table('stripe_connect_payouts')->insert([
            ['stripe_account_id'=>'acct_1','stripe_payout_id'=>'po_1','manufacturer_id'=>42,'amount'=>700,'currency'=>'EUR','status'=>'paid'],
            ['stripe_account_id'=>'acct_1','stripe_payout_id'=>'po_2','manufacturer_id'=>42,'amount'=>900,'currency'=>'EUR','status'=>'paid'],
        ]);
        DB::table('stripe_connect_transfers')->insert(['stripe_account_id'=>'acct_1','stripe_transfer_id'=>'tr_1','order_id'=>17,'manufacturer_id'=>42,'amount'=>1000,'currency'=>'EUR','status'=>'paid']);
        $this->service = new StripeManualPayoutAttributionService($db);
    }

    public function test_partial_payouts_are_capped_and_idempotent(): void
    {
        self::assertSame('preview', $this->attribute('po_1', 600, 'one', true));
        self::assertSame(0, DB::table('stripe_connect_payout_manual_allocations')->count());
        self::assertSame('recorded', $this->attribute('po_1', 600, 'one'));
        self::assertSame('already_recorded', $this->attribute('po_1', 600, 'one'));
        try {
            $this->attribute('po_2', 401, 'two');
            self::fail('Transfer cap should apply across payouts.');
        } catch (RuntimeException) {}
        self::assertSame('recorded', $this->attribute('po_2', 400, 'two'));
        self::assertSame(2, DB::table('stripe_connect_payout_manual_allocations')->count());
    }

    public function test_reversal_is_auditable_idempotent_and_releases_capacity(): void
    {
        $this->attribute('po_1', 600, 'one');
        $id = (int) DB::table('stripe_connect_payout_manual_allocations')->value('id');
        self::assertSame('recorded', $this->service->reverse($id, 'reverse1', 'Correction', 'evidence2', 'operator', false));
        self::assertSame('already_recorded', $this->service->reverse($id, 'reverse1', 'Correction', 'evidence2', 'operator', false));
        self::assertSame([-1, 1], DB::table('stripe_connect_payout_manual_allocations')->orderBy('direction')->pluck('direction')->all());
        self::assertSame('recorded', $this->attribute('po_2', 900, 'two'));
    }

    public function test_failed_old_payout_does_not_block_retry(): void
    {
        $this->attribute('po_1', 700, 'one');
        DB::table('stripe_connect_payouts')->where('stripe_payout_id', 'po_1')->update(['status' => 'failed']);
        self::assertSame('recorded', $this->attribute('po_2', 900, 'two'));
        self::assertSame(2, DB::table('stripe_connect_payout_manual_allocations')->count());
    }

    public function test_active_auto_attribution_blocks_manual_and_failed_one_does_not(): void
    {
        DB::table('stripe_connect_payout_allocations')->insert(['stripe_account_id'=>'acct_1','stripe_payout_id'=>'po_1','stripe_transfer_id'=>'tr_1','amount'=>700]);
        try {
            $this->attribute('po_2', 300, 'one');
            self::fail('Active automatic attribution must block manual attribution.');
        } catch (RuntimeException) {}
        DB::table('stripe_connect_payouts')->where('stripe_payout_id', 'po_1')->update(['status'=>'failed']);
        self::assertSame('recorded', $this->attribute('po_2', 900, 'two'));
    }

    public function test_repeated_evidence_values_do_not_bypass_field_limits(): void
    {
        $long = str_repeat('x', 101);
        $this->expectException(InvalidArgumentException::class);
        $this->service->attribute('acct_1', 'po_1', 'tr_1', 100, $long, $long, $long, $long, false);
    }

    public function test_command_defaults_to_preview_and_requires_explicit_apply(): void
    {
        $command = new \PS\Webservice\Commands\ManualStripePayoutAttributionCommand($this->service);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        $args = ['--account' => 'acct_1', '--payout' => 'po_1', '--transfer' => 'tr_1', '--amount-minor' => '400',
            '--key' => 'cli-1', '--reason' => 'Reviewed evidence', '--evidence' => 'report-1', '--operator' => 'admin'];
        self::assertSame(0, $tester->execute($args));
        self::assertSame(0, DB::table('stripe_connect_payout_manual_allocations')->count());
        self::assertSame(0, $tester->execute($args + ['--apply' => true]));
        self::assertSame(1, DB::table('stripe_connect_payout_manual_allocations')->count());
        self::assertStringContainsString('ID journal: 1', $tester->getDisplay());
        self::assertStringContainsString('Nessun fondo', $tester->getDisplay());
        self::assertSame(0, $tester->execute($args + ['--apply' => true]));
        self::assertSame(1, DB::table('stripe_connect_payout_manual_allocations')->count());
        self::assertStringContainsString('already_recorded', $tester->getDisplay());
    }

    private function attribute(string $payout, int $amount, string $key, bool $dryRun = false): string
    {
        return $this->service->attribute('acct_1', $payout, 'tr_1', $amount, $key, 'Documented payment', 'report-1', 'operator', $dryRun);
    }
}
