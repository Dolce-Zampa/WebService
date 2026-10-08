<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Financial\CommissionLine;
use PS\Webservice\Repositories\FinancialCommissionSnapshotRepository;
use PS\Webservice\Service\Financial\CommissionCalculator;
use PS\Webservice\Service\Financial\CommissionSnapshotService;

final class CommissionSnapshotServiceTest extends TestCase
{
    private CommissionSnapshotService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $capsule = new DB();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $schema = $capsule->schema();
        $schema->create('financial_commission_snapshots', function ($table): void {
            $table->increments('id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedBigInteger('artisan_id');
            $table->string('order_reference', 64)->nullable();
            $table->string('order_item_reference', 191)->nullable();
            $table->string('artisan_reference', 191)->nullable();
            $table->char('currency', 3);
            $table->boolean('artisan_subject_to_vat');
            $table->decimal('product_total_tax_incl', 26, 12);
            $table->decimal('product_total_tax_excl', 26, 12);
            $table->string('commission_rule_version', 80);
            $table->decimal('commission_rate', 8, 6);
            $table->string('base_basis', 32);
            $table->decimal('base_amount', 26, 12);
            $table->decimal('commission_amount', 26, 12);
            $table->decimal('artisan_amount', 26, 12);
            $table->string('rounding_mode', 32);
            $table->boolean('shipping_included');
            $table->dateTime('calculated_at');
            $table->dateTime('created_at');
            $table->unique(['order_id', 'order_item_id']);
        });

        $this->service = new CommissionSnapshotService(
            new CommissionCalculator(),
            new FinancialCommissionSnapshotRepository($capsule),
        );
    }

    public function test_professional_artisan_uses_discounted_tax_exclusive_product_total(): void
    {
        $result = $this->service->record($this->line([
            'artisan_subject_to_vat' => true,
            'product_total_tax_incl' => '122.000000000000',
            'product_total_tax_excl' => '100.000000000000',
        ]), new DateTimeImmutable('2026-10-08 16:00:00'));

        self::assertTrue($result->created);
        $snapshot = DB::table('financial_commission_snapshots')->find($result->snapshotId);
        self::assertSame('product_tax_exclusive', $snapshot->base_basis);
        self::assertSame('100', (string) $snapshot->base_amount);
        self::assertSame('20', (string) $snapshot->commission_amount);
        self::assertSame('80', (string) $snapshot->artisan_amount);
        self::assertSame('0', (string) $snapshot->shipping_included);
        self::assertSame('none', $snapshot->rounding_mode);
        self::assertSame('marketplace-commission-v1', $snapshot->commission_rule_version);
    }

    public function test_non_vat_artisan_uses_discounted_tax_inclusive_product_total(): void
    {
        $result = $this->service->record($this->line([
            'artisan_subject_to_vat' => false,
            'product_total_tax_incl' => '75.50',
            'product_total_tax_excl' => '61.885246',
        ]), new DateTimeImmutable('2026-10-08 16:00:00'));

        $snapshot = DB::table('financial_commission_snapshots')->find($result->snapshotId);
        self::assertSame('product_tax_inclusive', $snapshot->base_basis);
        self::assertSame('75.5', (string) $snapshot->base_amount);
        self::assertSame('15.1', (string) $snapshot->commission_amount);
        self::assertSame('60.4', (string) $snapshot->artisan_amount);
    }

    public function test_each_order_item_is_snapshotted_for_its_artisan_in_a_multi_artisan_order(): void
    {
        $first = $this->service->record($this->line([
            'order_item_id' => 501,
            'artisan_id' => 11,
            'artisan_subject_to_vat' => true,
            'product_total_tax_incl' => '36.60',
            'product_total_tax_excl' => '30.50',
        ]), new DateTimeImmutable('2026-10-08 16:00:00'));
        $second = $this->service->record($this->line([
            'order_item_id' => 502,
            'artisan_id' => 22,
            'artisan_subject_to_vat' => false,
            'product_total_tax_incl' => '25.00',
            'product_total_tax_excl' => '20.491803',
        ]), new DateTimeImmutable('2026-10-08 16:00:00'));

        self::assertTrue($first->created);
        self::assertTrue($second->created);
        self::assertSame(2, DB::table('financial_commission_snapshots')->count());
        self::assertSame('6.1', (string) DB::table('financial_commission_snapshots')->where('order_item_id', 501)->value('commission_amount'));
        self::assertSame('5', (string) DB::table('financial_commission_snapshots')->where('order_item_id', 502)->value('commission_amount'));
    }

    public function test_existing_snapshot_wins_over_a_future_calculation(): void
    {
        $line = $this->line(['product_total_tax_incl' => '100.00']);
        $first = $this->service->record($line, new DateTimeImmutable('2026-10-08 16:00:00'));
        $retryWithDifferentInputs = $this->service->record(
            $this->line(['product_total_tax_incl' => '1.00']),
            new DateTimeImmutable('2026-10-09 16:00:00'),
        );

        self::assertTrue($first->created);
        self::assertFalse($retryWithDifferentInputs->created);
        self::assertSame($first->snapshotId, $retryWithDifferentInputs->snapshotId);
        self::assertSame('100', (string) DB::table('financial_commission_snapshots')->value('base_amount'));
        self::assertSame('20', (string) DB::table('financial_commission_snapshots')->value('commission_amount'));
    }

    public function test_calculation_retains_fractional_result_without_rounding(): void
    {
        $calculation = (new CommissionCalculator())->calculate($this->line([
            'product_total_tax_incl' => '0.000001',
            'product_total_tax_excl' => '0.000001',
        ]));

        self::assertSame('0.000000200000', $calculation->commissionAmount);
        self::assertSame('0.000000800000', $calculation->artisanAmount);
    }

    /** @param array<string, mixed> $overrides */
    private function line(array $overrides = []): CommissionLine
    {
        return CommissionLine::fromArray(array_merge([
            'order_id' => 1001,
            'order_item_id' => 500,
            'artisan_id' => 10,
            'currency' => 'EUR',
            'product_total_tax_incl' => '100.00',
            'product_total_tax_excl' => '81.967213',
            'artisan_subject_to_vat' => false,
            'order_reference' => 'ORD-1001',
            'order_item_reference' => 'ROW-500',
            'artisan_reference' => 'ART-10',
        ], $overrides));
    }
}
