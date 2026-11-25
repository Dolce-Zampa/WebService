<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Repositories\FinancialCommissionSnapshotRepository;
use PS\Webservice\Repositories\FinancialTransactionRepository;
use PS\Webservice\Service\Financial\FinancialOrderReconciliationService;

final class FinancialOrderReconciliationServiceTest extends TestCase
{
    private FinancialOrderReconciliationService $service;

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
            $table->string('order_reference')->nullable();
            $table->string('artisan_reference')->nullable();
            $table->string('order_item_reference')->nullable();
            $table->string('type'); $table->string('status'); $table->decimal('amount', 20, 6); $table->char('currency', 3);
            $table->string('provider')->nullable(); $table->string('provider_account_id')->nullable();
            $table->string('provider_transaction_id')->nullable(); $table->string('provider_event_id')->nullable();
            $table->string('source_event_type')->nullable(); $table->string('source_event_id')->nullable();
            $table->unsignedBigInteger('related_transaction_id')->nullable(); $table->json('metadata')->nullable();
            $table->dateTime('occurred_at'); $table->dateTime('available_at')->nullable(); $table->dateTime('settled_at')->nullable();
            $table->timestamps();
        });
        $schema->create('financial_transaction_events', function ($table): void {
            $table->increments('id'); $table->unsignedBigInteger('financial_transaction_id');
            $table->string('event_type'); $table->string('status'); $table->string('idempotency_key');
            $table->string('source_event_type')->nullable(); $table->string('source_event_id')->nullable();
            $table->json('metadata')->nullable(); $table->dateTime('occurred_at'); $table->dateTime('received_at'); $table->dateTime('created_at');
        });
        $schema->create('financial_commission_snapshots', function ($table): void {
            $table->increments('id'); $table->unsignedBigInteger('order_id'); $table->unsignedBigInteger('order_item_id'); $table->unsignedBigInteger('artisan_id');
            $table->string('order_reference')->nullable(); $table->string('order_item_reference')->nullable(); $table->string('artisan_reference')->nullable(); $table->char('currency', 3);
            $table->boolean('artisan_subject_to_vat'); $table->decimal('product_total_tax_incl', 26, 12); $table->decimal('product_total_tax_excl', 26, 12);
            $table->string('commission_rule_version'); $table->decimal('commission_rate', 8, 6); $table->string('base_basis'); $table->decimal('base_amount', 26, 12);
            $table->decimal('commission_amount', 26, 12); $table->decimal('artisan_amount', 26, 12); $table->string('rounding_mode'); $table->boolean('shipping_included');
            $table->dateTime('calculated_at'); $table->dateTime('created_at');
        });
        $schema->create('stripe_connect_payouts', function ($table): void {
            $table->increments('id'); $table->string('stripe_account_id'); $table->string('stripe_payout_id'); $table->string('status');
        });
        $schema->create('stripe_connect_payout_allocations', function ($table): void {
            $table->increments('id'); $table->string('stripe_account_id'); $table->string('stripe_payout_id'); $table->string('stripe_transfer_id');
            $table->unsignedBigInteger('order_id'); $table->unsignedBigInteger('manufacturer_id'); $table->integer('amount'); $table->string('currency');
        });
        $schema->create('stripe_connect_payout_manual_allocations', function ($table): void {
            $table->increments('id'); $table->string('stripe_account_id'); $table->string('stripe_payout_id'); $table->string('stripe_transfer_id');
            $table->unsignedBigInteger('order_id'); $table->unsignedBigInteger('manufacturer_id'); $table->integer('amount'); $table->string('currency'); $table->integer('direction');
        });
        $schema->create('stripe_connect_transfers', function ($table): void {
            $table->increments('id'); $table->string('stripe_account_id'); $table->string('stripe_transfer_id');
        });
        $schema->create('stripe_connect_transfer_reversals', function ($table): void {
            $table->increments('id'); $table->unsignedBigInteger('stripe_connect_transfer_id');
        });

        $this->service = new FinancialOrderReconciliationService(
            new FinancialTransactionRepository($capsule),
            new FinancialCommissionSnapshotRepository($capsule),
        );
    }

    public function test_reconciles_collections_commissions_entitlement_payouts_and_refunds_without_rounding(): void
    {
        $this->movement(['type' => 'payment', 'amount' => '132.50', 'provider_transaction_id' => 'pi_order_1', 'provider_event_id' => 'evt_payment']);
        $this->movement(['type' => 'refund', 'amount' => '-12.50', 'related_transaction_id' => 1, 'provider_transaction_id' => 're_order_1']);
        $this->movement(['type' => 'payout', 'status' => 'paid', 'amount' => '-80', 'provider_transaction_id' => 'po_order_1']);
        $this->snapshot(['commission_amount' => '20.1', 'artisan_amount' => '80.4']);

        $report = $this->service->forOrder(101);

        self::assertSame('120', $report['financials']['collections']['net_collected']);
        self::assertSame('20.1', $report['financials']['commissions']['calculated']);
        self::assertSame('20.1', $report['financials']['commissions']['residual']);
        self::assertSame('80.4', $report['financials']['seller_entitlement']['calculated']);
        self::assertSame('0.4', $report['financials']['seller_entitlement']['residual']);
        self::assertSame('19.5', $report['financials']['differences']['unexplained_collection']);
        self::assertSame('not_recorded', $report['financials']['shipping']['status']);
        self::assertSame(['REF-101'], $report['identifiers']['order_references']);
        self::assertSame(['pi_order_1', 're_order_1', 'po_order_1'], $report['identifiers']['provider_transaction_ids']);
        self::assertSame(['po_order_1'], $report['identifiers']['payout_provider_transaction_ids']);
    }

    public function test_timeline_is_chronological_and_paginated(): void
    {
        $this->movement(['type' => 'payment', 'amount' => '10', 'occurred_at' => '2026-10-09 10:02:00']);
        $this->movement(['type' => 'refund', 'amount' => '-1', 'occurred_at' => '2026-10-09 10:01:00']);
        $this->movement(['type' => 'payout', 'amount' => '7', 'occurred_at' => '2026-10-09 10:03:00']);

        $report = $this->service->forOrder(101, 2, 1);

        self::assertSame(3, $report['timeline']['total']);
        self::assertSame(3, $report['timeline']['total_pages']);
        self::assertSame(2, $report['timeline']['page']);
        self::assertSame('payment', $report['timeline']['items'][0]['type']);
        self::assertArrayNotHasKey('metadata', $report['timeline']['items'][0]);
    }

    public function test_legacy_transfer_is_not_described_as_a_bank_payout(): void
    {
        $this->movement(['type' => 'payout', 'status' => 'paid', 'amount' => '-80', 'provider_transaction_id' => 'tr_old', 'source_event_type' => 'stripe.transfer.created']);
        $this->movement(['type' => 'payout', 'status' => 'paid', 'amount' => '10', 'provider_transaction_id' => 'trr_old', 'source_event_type' => 'stripe.transfer.reversal.created']);
        $this->snapshot(['artisan_amount' => '90']);
        $report = $this->service->forOrder(101);
        self::assertSame('70', $report['financials']['seller_entitlement']['transfers']);
        self::assertSame('20', $report['financials']['seller_entitlement']['transfer_residual']);
        self::assertSame('0', $report['financials']['seller_entitlement']['payouts']);
        self::assertSame(['amount' => null, 'status' => 'not_attributed', 'residual_from_attributed' => null, 'provider_transaction_ids' => [], 'invalidated_allocations' => []], $report['financials']['seller_entitlement']['connect_bank_payouts']);
        self::assertSame([], $report['identifiers']['payout_provider_transaction_ids']);
        self::assertSame(['tr_old', 'trr_old'], $report['identifiers']['transfer_provider_transaction_ids']);
        self::assertSame('transfer', $report['timeline']['items'][0]['type']);
        self::assertSame('available', $report['timeline']['items'][0]['status']);
    }

    public function test_paid_payout_totals_exclude_pending_and_failed_and_apply_linked_reversal(): void
    {
        $this->movement(['type' => 'payout', 'status' => 'paid', 'amount' => '-60']);
        $this->movement(['type' => 'payout', 'status' => 'pending', 'amount' => '-20']);
        $this->movement(['type' => 'payout', 'status' => 'failed', 'amount' => '-30']);
        $this->movement(['type' => 'payout', 'status' => 'reversed', 'amount' => '10', 'related_transaction_id' => 1]);
        $this->snapshot(['artisan_amount' => '100']);
        $report = $this->service->forOrder(101);
        self::assertSame('50', $report['financials']['seller_entitlement']['payouts']);
        self::assertSame('50', $report['financials']['seller_entitlement']['residual']);
    }

    public function test_verified_partial_connect_allocations_count_only_currently_paid_payouts(): void
    {
        $this->snapshot(['artisan_amount' => '100']);
        DB::table('stripe_connect_payouts')->insert([
            ['stripe_account_id' => 'acct_1', 'stripe_payout_id' => 'po_1', 'status' => 'paid'],
            ['stripe_account_id' => 'acct_1', 'stripe_payout_id' => 'po_2', 'status' => 'paid'],
            ['stripe_account_id' => 'acct_1', 'stripe_payout_id' => 'po_manual', 'status' => 'paid'],
            ['stripe_account_id' => 'acct_1', 'stripe_payout_id' => 'po_failed', 'status' => 'failed'],
        ]);
        DB::table('stripe_connect_payout_allocations')->insert([
            ['stripe_account_id' => 'acct_1', 'stripe_payout_id' => 'po_1', 'stripe_transfer_id' => 'tr_1', 'order_id' => 101, 'manufacturer_id' => 10, 'amount' => 3000, 'currency' => 'EUR'],
            ['stripe_account_id' => 'acct_1', 'stripe_payout_id' => 'po_2', 'stripe_transfer_id' => 'tr_2', 'order_id' => 101, 'manufacturer_id' => 10, 'amount' => 2000, 'currency' => 'EUR'],
            ['stripe_account_id' => 'acct_1', 'stripe_payout_id' => 'po_failed', 'stripe_transfer_id' => 'tr_f', 'order_id' => 101, 'manufacturer_id' => 10, 'amount' => 4000, 'currency' => 'EUR'],
            ['stripe_account_id' => 'acct_1', 'stripe_payout_id' => 'po_1', 'stripe_transfer_id' => 'tr_other', 'order_id' => 102, 'manufacturer_id' => 10, 'amount' => 9000, 'currency' => 'EUR'],
        ]);
        DB::table('stripe_connect_payout_manual_allocations')->insert([
            ['stripe_account_id' => 'acct_1', 'stripe_payout_id' => 'po_manual', 'stripe_transfer_id' => 'tr_manual', 'order_id' => 101, 'manufacturer_id' => 10, 'amount' => 1000, 'currency' => 'EUR', 'direction' => 1],
            ['stripe_account_id' => 'acct_1', 'stripe_payout_id' => 'po_failed', 'stripe_transfer_id' => 'tr_f', 'order_id' => 101, 'manufacturer_id' => 10, 'amount' => 1000, 'currency' => 'EUR', 'direction' => 1],
        ]);
        $entitlement = $this->service->forOrder(101)['financials']['seller_entitlement'];
        $bank = $entitlement['connect_bank_payouts'];
        self::assertSame(['amount' => '60', 'status' => 'attributed_amount', 'residual_from_attributed' => '40', 'provider_transaction_ids' => ['po_1', 'po_2', 'po_manual'], 'invalidated_allocations' => []], $bank);
        self::assertSame([['artisan_id' => 10, 'entitlement' => '100', 'attributed_payouts' => '60', 'residual_from_attributed' => '40']], $entitlement['by_artisan']);
        DB::table('stripe_connect_payout_manual_allocations')->insert(['stripe_account_id' => 'acct_1', 'stripe_payout_id' => 'po_manual', 'stripe_transfer_id' => 'tr_manual', 'order_id' => 101, 'manufacturer_id' => 10, 'amount' => 1000, 'currency' => 'EUR', 'direction' => -1]);
        self::assertSame('50', $this->service->forOrder(101)['financials']['seller_entitlement']['connect_bank_payouts']['amount']);
        $transferId = DB::table('stripe_connect_transfers')->insertGetId(['stripe_account_id' => 'acct_1', 'stripe_transfer_id' => 'tr_1']);
        DB::table('stripe_connect_transfer_reversals')->insert(['stripe_connect_transfer_id' => $transferId]);
        $invalidated = $this->service->forOrder(101)['financials']['seller_entitlement']['connect_bank_payouts'];
        self::assertSame('20', $invalidated['amount']);
        self::assertSame([['stripe_payout_id' => 'po_1', 'stripe_transfer_id' => 'tr_1', 'reason' => 'transfer_reversal']], $invalidated['invalidated_allocations']);
    }

    /** @param array<string, mixed> $overrides */
    private function movement(array $overrides): void
    {
        DB::table('financial_transactions')->insert(array_merge([
            'order_id' => 101, 'artisan_id' => 10, 'order_item_id' => null, 'order_reference' => 'REF-101', 'artisan_reference' => null, 'order_item_reference' => null,
            'type' => 'payment', 'status' => 'available', 'amount' => '0', 'currency' => 'EUR', 'provider' => 'stripe', 'provider_account_id' => null,
            'provider_transaction_id' => null, 'provider_event_id' => null, 'source_event_type' => null, 'source_event_id' => null, 'related_transaction_id' => null,
            'metadata' => null, 'occurred_at' => '2026-10-09 10:00:00', 'available_at' => null, 'settled_at' => null, 'created_at' => '2026-10-09 10:00:00', 'updated_at' => '2026-10-09 10:00:00',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function snapshot(array $overrides): void
    {
        DB::table('financial_commission_snapshots')->insert(array_merge([
            'order_id' => 101, 'order_item_id' => 1, 'artisan_id' => 10, 'order_reference' => 'REF-101', 'order_item_reference' => 'LINE-1', 'artisan_reference' => 'SELLER-10',
            'currency' => 'EUR', 'artisan_subject_to_vat' => false, 'product_total_tax_incl' => '100.5', 'product_total_tax_excl' => '100.5', 'commission_rule_version' => 'v1',
            'commission_rate' => '0.2', 'base_basis' => 'product_tax_inclusive', 'base_amount' => '100.5', 'commission_amount' => '0', 'artisan_amount' => '0',
            'rounding_mode' => 'none', 'shipping_included' => false, 'calculated_at' => '2026-10-09 10:00:00', 'created_at' => '2026-10-09 10:00:00',
        ], $overrides));
    }
}
