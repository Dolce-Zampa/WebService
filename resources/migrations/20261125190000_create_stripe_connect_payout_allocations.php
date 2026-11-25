<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateStripeConnectPayoutAllocations extends AbstractMigration
{
    public function up(): void
    {
        $prefix = (string) env('PS_TABLE_PREFIX', '');
        $this->table($prefix . 'stripe_connect_payout_allocations')
            ->addColumn('stripe_account_id', 'string', ['limit' => 191])
            ->addColumn('stripe_payout_id', 'string', ['limit' => 191])
            ->addColumn('stripe_balance_transaction_id', 'string', ['limit' => 191])
            ->addColumn('stripe_transfer_id', 'string', ['limit' => 191])
            ->addColumn('destination_payment_id', 'string', ['limit' => 191])
            ->addColumn('order_id', 'biginteger', ['signed' => false])
            ->addColumn('manufacturer_id', 'integer', ['signed' => false])
            ->addColumn('amount', 'biginteger', ['signed' => false])
            ->addColumn('currency', 'char', ['limit' => 3])
            ->addColumn('created_at', 'datetime')
            ->addIndex(['stripe_account_id', 'stripe_payout_id', 'stripe_balance_transaction_id'], ['unique' => true, 'name' => 'uniq_payout_allocation_balance'])
            ->addIndex(['stripe_account_id', 'stripe_transfer_id'], ['name' => 'idx_payout_allocation_transfer'])
            ->addIndex(['stripe_account_id', 'stripe_payout_id'], ['name' => 'idx_payout_allocation_payout'])
            ->addIndex(['order_id'], ['name' => 'idx_payout_allocation_order'])
            ->create();
    }

    public function down(): void
    {
        $table = (string) env('PS_TABLE_PREFIX', '') . 'stripe_connect_payout_allocations';
        if ($this->hasTable($table)) {
            if ((int) $this->fetchRow('SELECT COUNT(*) AS n FROM `' . $table . '`')['n'] > 0) {
                throw new RuntimeException('Cannot drop payout allocation evidence.');
            }
            $this->table($table)->drop()->save();
        }
    }
}
