<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePacklinkShipments extends AbstractMigration
{
    public function up(): void
    {
        $this->table((string) env('PS_TABLE_PREFIX', '') . 'packlink_shipments')
            ->addColumn('order_id', 'biginteger', ['signed' => false])
            ->addColumn('status', 'string', ['limit' => 32, 'default' => 'pending'])
            ->addColumn('provider_reference', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('last_error', 'string', ['limit' => 64, 'null' => true])
            ->addTimestamps()
            ->addIndex(['order_id'], ['unique' => true, 'name' => 'uniq_packlink_order'])
            ->addIndex(['provider_reference'], ['unique' => true, 'name' => 'uniq_packlink_reference'])
            ->addIndex(['status', 'id'], ['name' => 'idx_packlink_status'])
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
