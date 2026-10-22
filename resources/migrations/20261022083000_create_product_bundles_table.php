<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateProductBundlesTable extends AbstractMigration
{
    public function up(): void
    {
        $tableName = (string) env('PS_TABLE_PREFIX', '') . 'product_bundles';

        $this->table($tableName)
            ->addColumn('parent_product_id', 'integer', ['signed' => false])
            ->addColumn('bundled_product_id', 'integer', ['signed' => false])
            ->addColumn('reduction', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0])
            ->addColumn('reduction_type', 'enum', ['values' => ['amount', 'percentage'], 'default' => 'amount'])
            ->addColumn('active_reduction', 'boolean', ['default' => true])
            ->addColumn('position', 'integer', ['signed' => false, 'default' => 0])
            ->addTimestamps()
            ->addIndex(['parent_product_id', 'position'], ['name' => 'idx_product_bundles_parent_position'])
            ->addIndex(['bundled_product_id'], ['name' => 'idx_product_bundles_bundled'])
            ->addIndex(['parent_product_id', 'bundled_product_id'], ['unique' => true, 'name' => 'uniq_product_bundles_link'])
            ->create();

        $this->table($tableName)->insert($this->initialBundles())->saveData();
    }

    public function down(): void
    {
        $tableName = (string) env('PS_TABLE_PREFIX', '') . 'product_bundles';
        if ($this->hasTable($tableName)) {
            $this->table($tableName)->drop()->save();
        }
    }

    /** @return array<int, array<string, bool|float|int|string>> */
    private function initialBundles(): array
    {
        return [
            ['parent_product_id' => 114, 'bundled_product_id' => 56, 'reduction' => 25, 'reduction_type' => 'amount', 'active_reduction' => true, 'position' => 0],
            ['parent_product_id' => 114, 'bundled_product_id' => 115, 'reduction' => 10, 'reduction_type' => 'amount', 'active_reduction' => true, 'position' => 1],
            ['parent_product_id' => 114, 'bundled_product_id' => 29, 'reduction' => 10, 'reduction_type' => 'amount', 'active_reduction' => true, 'position' => 2],
            ['parent_product_id' => 113, 'bundled_product_id' => 56, 'reduction' => 25, 'reduction_type' => 'amount', 'active_reduction' => true, 'position' => 0],
            ['parent_product_id' => 113, 'bundled_product_id' => 115, 'reduction' => 10, 'reduction_type' => 'amount', 'active_reduction' => true, 'position' => 1],
            ['parent_product_id' => 112, 'bundled_product_id' => 56, 'reduction' => 25, 'reduction_type' => 'amount', 'active_reduction' => true, 'position' => 0],
            ['parent_product_id' => 115, 'bundled_product_id' => 143, 'reduction' => 10, 'reduction_type' => 'percentage', 'active_reduction' => true, 'position' => 0],
            ['parent_product_id' => 115, 'bundled_product_id' => 144, 'reduction' => 10, 'reduction_type' => 'percentage', 'active_reduction' => true, 'position' => 1],
            ['parent_product_id' => 115, 'bundled_product_id' => 114, 'reduction' => 10, 'reduction_type' => 'percentage', 'active_reduction' => true, 'position' => 2],
            ['parent_product_id' => 141, 'bundled_product_id' => 148, 'reduction' => 10, 'reduction_type' => 'percentage', 'active_reduction' => true, 'position' => 0],
            ['parent_product_id' => 141, 'bundled_product_id' => 149, 'reduction' => 10, 'reduction_type' => 'percentage', 'active_reduction' => true, 'position' => 1],
            ['parent_product_id' => 155, 'bundled_product_id' => 160, 'reduction' => 16, 'reduction_type' => 'amount', 'active_reduction' => true, 'position' => 0],
            ['parent_product_id' => 127, 'bundled_product_id' => 160, 'reduction' => 16, 'reduction_type' => 'amount', 'active_reduction' => true, 'position' => 0],
            ['parent_product_id' => 123, 'bundled_product_id' => 160, 'reduction' => 16, 'reduction_type' => 'amount', 'active_reduction' => true, 'position' => 0],
            ['parent_product_id' => 104, 'bundled_product_id' => 160, 'reduction' => 16, 'reduction_type' => 'amount', 'active_reduction' => true, 'position' => 0],
            ['parent_product_id' => 103, 'bundled_product_id' => 160, 'reduction' => 16, 'reduction_type' => 'amount', 'active_reduction' => true, 'position' => 0],
            ['parent_product_id' => 102, 'bundled_product_id' => 160, 'reduction' => 16, 'reduction_type' => 'amount', 'active_reduction' => true, 'position' => 0],
            ['parent_product_id' => 100, 'bundled_product_id' => 160, 'reduction' => 16, 'reduction_type' => 'amount', 'active_reduction' => true, 'position' => 0],
            ['parent_product_id' => 95, 'bundled_product_id' => 160, 'reduction' => 16, 'reduction_type' => 'amount', 'active_reduction' => true, 'position' => 0],
            ['parent_product_id' => 99, 'bundled_product_id' => 160, 'reduction' => 16, 'reduction_type' => 'amount', 'active_reduction' => true, 'position' => 0],
            ['parent_product_id' => 94, 'bundled_product_id' => 160, 'reduction' => 16, 'reduction_type' => 'amount', 'active_reduction' => true, 'position' => 0],
            ['parent_product_id' => 93, 'bundled_product_id' => 160, 'reduction' => 16, 'reduction_type' => 'amount', 'active_reduction' => true, 'position' => 0],
            ['parent_product_id' => 86, 'bundled_product_id' => 160, 'reduction' => 16, 'reduction_type' => 'amount', 'active_reduction' => true, 'position' => 0],
            ['parent_product_id' => 244, 'bundled_product_id' => 253, 'reduction' => 0, 'reduction_type' => 'amount', 'active_reduction' => false, 'position' => 0],
            ['parent_product_id' => 245, 'bundled_product_id' => 253, 'reduction' => 0, 'reduction_type' => 'amount', 'active_reduction' => false, 'position' => 0],
            ['parent_product_id' => 246, 'bundled_product_id' => 253, 'reduction' => 0, 'reduction_type' => 'amount', 'active_reduction' => false, 'position' => 0],
            ['parent_product_id' => 247, 'bundled_product_id' => 253, 'reduction' => 0, 'reduction_type' => 'amount', 'active_reduction' => false, 'position' => 0],
            ['parent_product_id' => 248, 'bundled_product_id' => 253, 'reduction' => 0, 'reduction_type' => 'amount', 'active_reduction' => false, 'position' => 0],
        ];
    }
}
