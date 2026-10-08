<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Financial movements are an audit ledger. Foreign keys are deliberately not
 * used because the referenced PrestaShop entities can be changed or removed
 * after the financial event has been recorded; the snapshot reference columns
 * keep the movement independently traceable.
 */
final class CreateFinancialTransactionsTable extends AbstractMigration
{
    public function up(): void
    {
        $tableName = (string) env('PS_TABLE_PREFIX', '') . 'financial_transactions';

        $table = $this->table($tableName);
        $table
            ->addColumn('order_id', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('artisan_id', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('order_item_id', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('order_reference', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('artisan_reference', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('order_item_reference', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('type', 'enum', [
                'values' => ['payment', 'refund', 'commission', 'payout', 'provider_fee', 'chargeback', 'adjustment'],
            ])
            ->addColumn('status', 'enum', [
                'values' => ['pending', 'available', 'paid', 'reversed', 'failed', 'cancelled'],
                'default' => 'pending',
            ])
            ->addColumn('amount', 'decimal', ['precision' => 20, 'scale' => 6])
            ->addColumn('currency', 'char', ['limit' => 3])
            ->addColumn('provider', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('provider_account_id', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('provider_transaction_id', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('provider_event_id', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('source_event_type', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('source_event_id', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('related_transaction_id', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('metadata', 'json', ['null' => true])
            ->addColumn('occurred_at', 'datetime')
            ->addColumn('available_at', 'datetime', ['null' => true])
            ->addColumn('settled_at', 'datetime', ['null' => true])
            ->addTimestamps()
            ->addIndex(['order_id', 'occurred_at'], ['name' => 'idx_financial_transactions_order_period'])
            ->addIndex(['artisan_id', 'occurred_at'], ['name' => 'idx_financial_transactions_artisan_period'])
            ->addIndex(['order_item_id'], ['name' => 'idx_financial_transactions_order_item'])
            ->addIndex(['occurred_at'], ['name' => 'idx_financial_transactions_period'])
            ->addIndex(['status', 'occurred_at'], ['name' => 'idx_financial_transactions_status_period'])
            ->addIndex(['provider', 'provider_transaction_id'], ['name' => 'idx_financial_transactions_provider_transaction'])
            ->addIndex(['provider', 'provider_event_id'], ['unique' => true, 'name' => 'uniq_financial_transactions_provider_event'])
            ->addIndex(['source_event_type', 'source_event_id'], ['name' => 'idx_financial_transactions_source_event'])
            ->addIndex(['related_transaction_id'], ['name' => 'idx_financial_transactions_related'])
            ->create();
    }

    public function down(): void
    {
        $tableName = (string) env('PS_TABLE_PREFIX', '') . 'financial_transactions';

        if ($this->hasTable($tableName)) {
            $this->table($tableName)->drop()->save();
        }
    }
}
