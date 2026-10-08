<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Calculation evidence is deliberately separate from ledger movements:
 * commission and payout entries may settle at different times, while the
 * policy and per-order-item basis must never be recalculated.
 */
final class CreateFinancialCommissionSnapshotsTable extends AbstractMigration
{
    public function up(): void
    {
        $tableName = (string) env('PS_TABLE_PREFIX', '') . 'financial_commission_snapshots';
        $this->table($tableName)
            ->addColumn('order_id', 'biginteger', ['signed' => false])
            ->addColumn('order_item_id', 'biginteger', ['signed' => false])
            ->addColumn('artisan_id', 'biginteger', ['signed' => false])
            ->addColumn('order_reference', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('order_item_reference', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('artisan_reference', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('currency', 'char', ['limit' => 3])
            ->addColumn('artisan_subject_to_vat', 'boolean')
            ->addColumn('product_total_tax_incl', 'decimal', ['precision' => 26, 'scale' => 12])
            ->addColumn('product_total_tax_excl', 'decimal', ['precision' => 26, 'scale' => 12])
            ->addColumn('commission_rule_version', 'string', ['limit' => 80])
            ->addColumn('commission_rate', 'decimal', ['precision' => 8, 'scale' => 6])
            ->addColumn('base_basis', 'enum', ['values' => ['product_tax_inclusive', 'product_tax_exclusive']])
            ->addColumn('base_amount', 'decimal', ['precision' => 26, 'scale' => 12])
            ->addColumn('commission_amount', 'decimal', ['precision' => 26, 'scale' => 12])
            ->addColumn('artisan_amount', 'decimal', ['precision' => 26, 'scale' => 12])
            ->addColumn('rounding_mode', 'string', ['limit' => 32])
            ->addColumn('shipping_included', 'boolean', ['default' => false])
            ->addColumn('calculated_at', 'datetime')
            ->addColumn('created_at', 'datetime')
            ->addIndex(['order_id', 'order_item_id'], ['unique' => true, 'name' => 'uniq_financial_commission_order_item'])
            ->addIndex(['artisan_id', 'calculated_at'], ['name' => 'idx_financial_commission_artisan_period'])
            ->create();
    }

    public function down(): void
    {
        $tableName = (string) env('PS_TABLE_PREFIX', '') . 'financial_commission_snapshots';
        if ($this->hasTable($tableName)) {
            $this->table($tableName)->drop()->save();
        }
    }
}
