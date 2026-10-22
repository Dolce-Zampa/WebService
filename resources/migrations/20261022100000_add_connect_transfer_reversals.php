<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Records seller-side recovery independently from the platform Refund or
 * dispute. Stripe does not reverse a Transfer automatically when the
 * platform refunds a customer, therefore each recovery attempt needs its own
 * immutable, idempotent record.
 */
final class AddConnectTransferReversals extends AbstractMigration
{
    public function up(): void
    {
        $prefix = (string) env('PS_TABLE_PREFIX', '');
        $transfers = $prefix . 'stripe_connect_transfers';

        $this->table($transfers)
            ->addColumn('original_amount', 'integer', ['signed' => false, 'default' => 0, 'after' => 'amount'])
            ->update();
        $quotedTransfers = str_replace('`', '``', $transfers);
        $this->execute(sprintf(
            'UPDATE `%s` SET `original_amount` = `amount` WHERE `original_amount` = 0',
            $quotedTransfers,
        ));
        $this->table($transfers)
            ->changeColumn('original_amount', 'integer', ['signed' => false])
            ->update();

        $reversals = $this->table($prefix . 'stripe_connect_transfer_reversals');
        $reversals
            ->addColumn('stripe_connect_transfer_id', 'integer', ['signed' => false])
            ->addColumn('kind', 'string', ['limit' => 16])
            ->addColumn('provider_case_id', 'string', ['limit' => 191])
            ->addColumn('stripe_transfer_reversal_id', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('source_amount', 'integer', ['signed' => false])
            ->addColumn('amount', 'integer', ['signed' => false])
            ->addColumn('currency', 'char', ['limit' => 3])
            ->addColumn('status', 'string', ['limit' => 32])
            ->addColumn('failure_code', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('idempotency_key', 'string', ['limit' => 191])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addIndex(['stripe_connect_transfer_id', 'kind', 'provider_case_id'], ['unique' => true, 'name' => 'uniq_connect_transfer_reversal_case'])
            ->addIndex(['stripe_transfer_reversal_id'], ['unique' => true, 'name' => 'uniq_connect_transfer_reversal_provider'])
            ->addIndex(['idempotency_key'], ['unique' => true, 'name' => 'uniq_connect_transfer_reversal_idempotency'])
            ->addIndex(['kind', 'status'], ['name' => 'idx_connect_transfer_reversal_queue'])
            ->create();
    }

    public function down(): void
    {
        $prefix = (string) env('PS_TABLE_PREFIX', '');
        $reversals = $prefix . 'stripe_connect_transfer_reversals';
        if ($this->hasTable($reversals)) {
            $this->table($reversals)->drop()->save();
        }

        $transfers = $prefix . 'stripe_connect_transfers';
        if ($this->hasTable($transfers)) {
            $this->table($transfers)->removeColumn('original_amount')->update();
        }
    }
}
