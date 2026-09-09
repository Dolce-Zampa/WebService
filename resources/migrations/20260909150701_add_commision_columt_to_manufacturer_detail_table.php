<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddCommisionColumtToManufacturerDetailTable extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function up(): void
    {
        $this->table('fy8ie_manufacturer_details')
            ->addColumn('commission', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0.00, 'null' => false])
            ->update();
    }

    public function down(): void
    {
        $this->table('fy8ie_manufacturer_detail')
            ->removeColumn('commission')
            ->update();
    }
}
