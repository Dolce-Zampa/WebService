<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateStripeConnectPayouts extends AbstractMigration
{
    public function up(): void
    {
        $prefix = (string) env('PS_TABLE_PREFIX', '');
        $this->table($prefix . 'stripe_connect_payouts')
            ->addColumn('stripe_account_id', 'string', ['limit' => 191])
            ->addColumn('stripe_payout_id', 'string', ['limit' => 191])
            ->addColumn('manufacturer_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('amount', 'biginteger', ['signed' => false])
            ->addColumn('currency', 'char', ['limit' => 3])
            ->addColumn('status', 'string', ['limit' => 16])
            ->addColumn('status_source', 'string', ['limit' => 24])
            ->addColumn('arrival_date', 'datetime', ['null' => true])
            ->addColumn('failure_code', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('status_event_created', 'biginteger', ['signed' => false])
            ->addColumn('status_event_id', 'string', ['limit' => 191])
            ->addTimestamps()
            ->addIndex(['stripe_account_id', 'stripe_payout_id'], ['unique' => true, 'name' => 'uniq_connect_payout_account_payout'])
            ->addIndex(['manufacturer_id', 'status_event_created'], ['name' => 'idx_connect_payout_seller_event'])
            ->create();

        // Keep every verified provider event independently of the current snapshot.
        $this->table($prefix . 'stripe_connect_payout_events')
            ->addColumn('stripe_account_id', 'string', ['limit' => 191])
            ->addColumn('stripe_payout_id', 'string', ['limit' => 191])
            ->addColumn('stripe_event_id', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('event_type', 'string', ['limit' => 80])
            ->addColumn('status', 'string', ['limit' => 16])
            ->addColumn('arrival_date', 'datetime', ['null' => true])
            ->addColumn('failure_code', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('event_created', 'biginteger', ['signed' => false])
            ->addColumn('received_at', 'datetime')
            ->addIndex(['stripe_event_id'], ['unique' => true, 'name' => 'uniq_connect_payout_event'])
            ->addIndex(['stripe_account_id', 'stripe_payout_id', 'event_created'], ['name' => 'idx_connect_payout_event_timeline'])
            ->create();
    }

    public function down(): void
    {
        $prefix = (string) env('PS_TABLE_PREFIX', '');
        foreach (['stripe_connect_payout_events', 'stripe_connect_payouts'] as $table) {
            if ($this->hasTable($prefix . $table) && (int) $this->fetchRow('SELECT COUNT(*) AS n FROM `' . $prefix . $table . '`')['n'] > 0) {
                throw new RuntimeException('Cannot drop payout audit records.');
            }
        }
        foreach (['stripe_connect_payout_events', 'stripe_connect_payouts'] as $table) {
            if ($this->hasTable($prefix . $table)) {
                $this->table($prefix . $table)->drop()->save();
            }
        }
    }
}
