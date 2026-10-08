<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The event journal is deliberately separate from financial_transactions.
 * Updating a status in place would make it impossible to reconstruct which
 * state was known at a given time. No foreign key is used for the same audit
 * retention reason as the financial transactions table.
 */
final class CreateFinancialTransactionEventsTable extends AbstractMigration
{
    public function up(): void
    {
        $tableName = (string) env('PS_TABLE_PREFIX', '') . 'financial_transaction_events';

        $this->table($tableName)
            ->addColumn('financial_transaction_id', 'biginteger', ['signed' => false])
            ->addColumn('event_type', 'enum', [
                'values' => ['recorded', 'status_changed', 'correction', 'reversal'],
            ])
            ->addColumn('status', 'enum', [
                'values' => ['pending', 'available', 'paid', 'reversed', 'failed', 'cancelled'],
            ])
            ->addColumn('idempotency_key', 'string', ['limit' => 191])
            ->addColumn('source_event_type', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('source_event_id', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('metadata', 'json', ['null' => true])
            ->addColumn('occurred_at', 'datetime')
            ->addColumn('received_at', 'datetime')
            ->addColumn('created_at', 'datetime')
            ->addIndex(['idempotency_key'], ['unique' => true, 'name' => 'uniq_financial_transaction_events_idempotency'])
            ->addIndex(['financial_transaction_id', 'occurred_at'], ['name' => 'idx_financial_transaction_events_timeline'])
            ->addIndex(['source_event_type', 'source_event_id'], ['name' => 'idx_financial_transaction_events_source'])
            ->create();
    }

    public function down(): void
    {
        $tableName = (string) env('PS_TABLE_PREFIX', '') . 'financial_transaction_events';

        if ($this->hasTable($tableName)) {
            $this->table($tableName)->drop()->save();
        }
    }
}
