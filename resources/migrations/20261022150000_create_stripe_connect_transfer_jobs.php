<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A verified platform payment must retain an allocation work item even when
 * the PrestaShop order has not become readable yet. This queue deliberately
 * contains only payment correlation and seller allocation facts, never buyer
 * or delivery data.
 */
final class CreateStripeConnectTransferJobs extends AbstractMigration
{
    public function up(): void
    {
        $prefix = (string) env('PS_TABLE_PREFIX', '');
        $this->table($prefix . 'stripe_connect_transfer_jobs')
            ->addColumn('cart_id', 'integer', ['signed' => false])
            ->addColumn('order_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('stripe_payment_intent_id', 'string', ['limit' => 191])
            ->addColumn('transfer_group', 'string', ['limit' => 191])
            ->addColumn('occurred_at', 'datetime')
            ->addColumn('allocation_snapshot', 'text', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 32, 'default' => 'pending'])
            ->addColumn('attempt_count', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('last_error', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('next_attempt_at', 'datetime')
            ->addColumn('completed_at', 'datetime', ['null' => true])
            ->addTimestamps()
            ->addIndex(['stripe_payment_intent_id'], ['unique' => true, 'name' => 'uniq_connect_transfer_job_intent'])
            ->addIndex(['status', 'next_attempt_at', 'id'], ['name' => 'idx_connect_transfer_job_retry'])
            ->addIndex(['cart_id', 'created_at'], ['name' => 'idx_connect_transfer_job_cart'])
            ->create();
    }

    public function down(): void
    {
        $tableName = (string) env('PS_TABLE_PREFIX', '') . 'stripe_connect_transfer_jobs';
        if ($this->hasTable($tableName)) {
            $this->table($tableName)->drop()->save();
        }
    }
}
