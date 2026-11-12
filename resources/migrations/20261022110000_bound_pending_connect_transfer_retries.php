<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * An unavailable seller leaves a durable pending allocation. A Transfer that
 * reaches Stripe but times out locally must be retried with its idempotency
 * key, yet a persistent failure needs an explicit operational reconciliation.
 */
final class BoundPendingConnectTransferRetries extends AbstractMigration
{
    public function up(): void
    {
        $prefix = (string) env('PS_TABLE_PREFIX', '');
        $this->table($prefix . 'stripe_connect_transfers')
            ->addColumn('attempt_count', 'integer', ['signed' => false, 'default' => 0, 'after' => 'failure_code'])
            ->addColumn('last_attempted_at', 'datetime', ['null' => true, 'after' => 'attempt_count'])
            ->addIndex(['status', 'attempt_count', 'id'], ['name' => 'idx_stripe_connect_transfers_retry'])
            ->update();
    }

    public function down(): void
    {
        $prefix = (string) env('PS_TABLE_PREFIX', '');
        $this->table($prefix . 'stripe_connect_transfers')
            ->removeIndexByName('idx_stripe_connect_transfers_retry')
            ->removeColumn('last_attempted_at')
            ->removeColumn('attempt_count')
            ->update();
    }
}
