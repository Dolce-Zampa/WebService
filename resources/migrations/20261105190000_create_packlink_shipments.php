<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePacklinkShipments extends AbstractMigration
{
    public function up(): void
    {
        $this->table((string) env('PS_TABLE_PREFIX', '') . 'packlink_shipments', ['engine' => 'InnoDB'])
            ->addColumn('order_id', 'biginteger', ['signed' => false])
            ->addColumn('status', 'string', ['limit' => 32, 'default' => 'pending'])
            ->addColumn('provider_reference', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('last_error', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('attempt_count', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('next_attempt_at', 'datetime', ['null' => true])
            ->addColumn('provider_state', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('tracking_number', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('tracking_url', 'text', ['null' => true])
            ->addColumn('labels_json', 'text', ['null' => true])
            ->addColumn('last_state_rank', 'integer', ['null' => true])
            ->addColumn('last_order_state', 'integer', ['null' => true])
            ->addColumn('sync_state', 'string', ['limit' => 32, 'default' => 'idle'])
            ->addColumn('sync_attempt_count', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('sync_due_at', 'datetime', ['null' => true])
            ->addColumn('last_sync_error', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('last_synced_at', 'datetime', ['null' => true])
            ->addTimestamps()
            ->addIndex(['order_id'], ['unique' => true, 'name' => 'uniq_packlink_order'])
            ->addIndex(['provider_reference'], ['unique' => true, 'name' => 'uniq_packlink_reference'])
            ->addIndex(['status', 'id'], ['name' => 'idx_packlink_status'])
            ->addIndex(['sync_state', 'sync_due_at', 'id'], ['name' => 'idx_packlink_sync'])
            ->create();
    }

    public function down(): void
    {
        $table = (string) env('PS_TABLE_PREFIX', '') . 'packlink_shipments';
        if ($this->hasTable($table)) {
            $this->table($table)->drop()->save();
        }
    }
}
