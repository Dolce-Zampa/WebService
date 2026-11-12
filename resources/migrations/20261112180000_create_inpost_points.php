<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateInpostPoints extends AbstractMigration
{
    public function up(): void
    {
        $prefix = (string) env('PS_TABLE_PREFIX', '');
        $this->table($prefix . 'inpost_points', ['id' => false, 'primary_key' => ['point_id']])
            ->addColumn('point_id', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('type', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('address', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('city', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('province', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('postal_code', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('latitude', 'decimal', ['precision' => 10, 'scale' => 7, 'null' => true])
            ->addColumn('longitude', 'decimal', ['precision' => 10, 'scale' => 7, 'null' => true])
            ->addColumn('opening_hours', 'text', ['null' => true])
            ->addIndex(['postal_code'], ['name' => 'idx_inpost_postal_code'])
            ->addIndex(['city'], ['name' => 'idx_inpost_city'])
            ->create();

        $this->table($prefix . 'inpost_sync', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['null' => false])
            ->addColumn('last_success_at', 'datetime', ['null' => true])
            ->addColumn('point_count', 'integer', ['default' => 0, 'null' => false])
            ->create();
        $this->table($prefix . 'inpost_sync')->insert(['id' => 1, 'last_success_at' => null, 'point_count' => 0])->saveData();
    }

    public function down(): void
    {
        $prefix = (string) env('PS_TABLE_PREFIX', '');
        $this->table($prefix . 'inpost_points')->drop()->save();
        $this->table($prefix . 'inpost_sync')->drop()->save();
    }
}
