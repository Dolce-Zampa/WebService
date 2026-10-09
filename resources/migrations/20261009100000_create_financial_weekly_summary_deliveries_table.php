<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Delivery state is intentionally free of recipient email, report contents,
 * and provider responses.  It supports exactly one completed delivery per
 * seller/reporting period and leaves failed retries auditable.
 */
final class CreateFinancialWeeklySummaryDeliveriesTable extends AbstractMigration
{
    public function up(): void
    {
        $tableName = (string) env('PS_TABLE_PREFIX', '') . 'financial_weekly_summary_deliveries';

        $this->table($tableName)
            ->addColumn('artisan_id', 'biginteger', ['signed' => false])
            ->addColumn('period_start', 'datetime')
            ->addColumn('period_end', 'datetime')
            ->addColumn('timezone', 'string', ['limit' => 64])
            ->addColumn('status', 'enum', ['values' => ['sending', 'sent', 'failed'], 'default' => 'sending'])
            ->addColumn('attempts', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('failure_code', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('locked_until', 'datetime', ['null' => true])
            ->addColumn('sent_at', 'datetime', ['null' => true])
            ->addTimestamps()
            ->addIndex(['artisan_id', 'period_start', 'timezone'], ['unique' => true, 'name' => 'uniq_financial_weekly_summary_delivery'])
            ->addIndex(['status', 'locked_until'], ['name' => 'idx_financial_weekly_summary_retry'])
            ->create();
    }

    public function down(): void
    {
        $tableName = (string) env('PS_TABLE_PREFIX', '') . 'financial_weekly_summary_deliveries';
        if ($this->hasTable($tableName)) {
            $this->table($tableName)->drop()->save();
        }
    }
}
