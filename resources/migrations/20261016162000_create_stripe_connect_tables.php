<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Connect state is kept outside PrestaShop's manufacturer tables so that
 * Stripe identifiers and onboarding lifecycle changes remain auditable.
 */
final class CreateStripeConnectTables extends AbstractMigration
{
    public function up(): void
    {
        $prefix = (string) env('PS_TABLE_PREFIX', '');

        $accounts = $this->table($prefix . 'stripe_connect_accounts');
        $accounts
            ->addColumn('manufacturer_id', 'integer', ['signed' => false])
            ->addColumn('stripe_account_id', 'string', ['limit' => 191])
            ->addColumn('account_type', 'string', ['limit' => 32, 'default' => 'express'])
            ->addColumn('country', 'char', ['limit' => 2])
            ->addColumn('details_submitted', 'boolean', ['default' => false])
            ->addColumn('charges_enabled', 'boolean', ['default' => false])
            ->addColumn('payouts_enabled', 'boolean', ['default' => false])
            ->addColumn('transfers_enabled', 'boolean', ['default' => false])
            ->addColumn('requirements_due', 'json', ['null' => true])
            ->addColumn('last_synced_at', 'datetime', ['null' => true])
            ->addTimestamps()
            ->addIndex(['manufacturer_id'], ['unique' => true, 'name' => 'uniq_stripe_connect_accounts_manufacturer'])
            ->addIndex(['stripe_account_id'], ['unique' => true, 'name' => 'uniq_stripe_connect_accounts_account'])
            ->addForeignKey(['manufacturer_id'], $prefix . 'manufacturer', ['id_manufacturer'], ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->create();

        $transfers = $this->table($prefix . 'stripe_connect_transfers');
        $transfers
            ->addColumn('order_id', 'biginteger', ['signed' => false])
            ->addColumn('manufacturer_id', 'integer', ['signed' => false])
            ->addColumn('stripe_account_id', 'string', ['limit' => 191])
            ->addColumn('stripe_payment_intent_id', 'string', ['limit' => 191])
            ->addColumn('stripe_charge_id', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('stripe_transfer_id', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('transfer_group', 'string', ['limit' => 191])
            ->addColumn('idempotency_key', 'string', ['limit' => 191])
            ->addColumn('amount', 'integer', ['signed' => false])
            ->addColumn('currency', 'char', ['limit' => 3])
            ->addColumn('status', 'string', ['limit' => 32, 'default' => 'pending'])
            ->addColumn('failure_code', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addIndex(['stripe_transfer_id'], ['unique' => true, 'name' => 'uniq_stripe_connect_transfers_transfer'])
            ->addIndex(['idempotency_key'], ['unique' => true, 'name' => 'uniq_stripe_connect_transfers_idempotency'])
            ->addIndex(['order_id', 'manufacturer_id'], ['name' => 'idx_stripe_connect_transfers_order_manufacturer'])
            ->addForeignKey(['manufacturer_id'], $prefix . 'manufacturer', ['id_manufacturer'], ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->create();
    }

    public function down(): void
    {
        $prefix = (string) env('PS_TABLE_PREFIX', '');
        foreach (['stripe_connect_transfers', 'stripe_connect_accounts'] as $table) {
            if ($this->hasTable($prefix . $table)) {
                $this->table($prefix . $table)->drop()->save();
            }
        }
    }
}
