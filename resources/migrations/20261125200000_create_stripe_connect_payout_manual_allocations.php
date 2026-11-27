<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateStripeConnectPayoutManualAllocations extends AbstractMigration
{
    public function up(): void
    {
        $table = (string) env('PS_TABLE_PREFIX', '') . 'stripe_connect_payout_manual_allocations';
        $this->table($table)
            ->addColumn('stripe_account_id', 'string', ['limit' => 191])
            ->addColumn('stripe_payout_id', 'string', ['limit' => 191])
            ->addColumn('stripe_transfer_id', 'string', ['limit' => 191])
            ->addColumn('order_id', 'biginteger', ['signed' => false])
            ->addColumn('manufacturer_id', 'integer', ['signed' => false])
            ->addColumn('amount', 'biginteger', ['signed' => false])
            ->addColumn('direction', 'integer')
            ->addColumn('reverses_allocation_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('currency', 'char', ['limit' => 3])
            ->addColumn('idempotency_key', 'string', ['limit' => 191])
            ->addColumn('reason', 'string', ['limit' => 255])
            ->addColumn('evidence_reference', 'string', ['limit' => 191])
            ->addColumn('operator', 'string', ['limit' => 100])
            ->addColumn('created_at', 'datetime')
            ->addIndex(['idempotency_key'], ['unique' => true, 'name' => 'uniq_manual_payout_idempotency'])
            ->addIndex(['reverses_allocation_id'], ['unique' => true, 'name' => 'uniq_manual_payout_reversal'])
            ->addIndex(['stripe_account_id', 'stripe_payout_id'], ['name' => 'idx_manual_payout'])
            ->addIndex(['stripe_account_id', 'stripe_transfer_id'], ['name' => 'idx_manual_transfer'])
            ->addIndex(['order_id', 'manufacturer_id'], ['name' => 'idx_manual_order_seller'])
            ->create();
    }

    public function down(): void
    {
        $table = (string) env('PS_TABLE_PREFIX', '') . 'stripe_connect_payout_manual_allocations';
        if ($this->hasTable($table)) {
            if ((int) $this->fetchRow('SELECT COUNT(*) AS n FROM `' . $table . '`')['n'] > 0) {
                throw new RuntimeException('Cannot drop manual payout attribution evidence.');
            }
            $this->table($table)->drop()->save();
        }
    }
}
