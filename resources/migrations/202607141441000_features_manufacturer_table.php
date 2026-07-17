<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class FeaturesManufacturerTable extends AbstractMigration
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

        $table = $this->table(env('PS_TABLE_PREFIX').'manufacturer');
        $table
            ->addColumn('uuid', 'string', ['limit' => 36, 'null' => true, 'after' => 'id_manufacturer'])
            ->addColumn('email', 'string', ['limit' => 255, 'null' => true, 'after' => 'uuid'])
            ->addColumn('sub', 'string', ['limit' => 255, 'null' => true])
            ->update();

        //create new table for manufatcurer fiscal details
        $table = $this->table(env('PS_TABLE_PREFIX').'manufacturer_details');
        $table
            ->addColumn('id_manufacturer', 'integer', ['limit' => 11, 'null' => false])
            ->addColumn('fiscal_code', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('vat_number', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('address', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('city', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('state', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('country', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('zip_code', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('phone_number', 'string', ['limit' => 20, 'null' => true])
            ->create();

        $table = $this->table(env('PS_TABLE_PREFIX') . 'manufacturer_details');

        if (!$table->hasColumn('first_name')) {
            $table->addColumn('first_name', 'string', ['limit' => 255, 'null' => true, 'after' => 'id_manufacturer']);
        }

        if (!$table->hasColumn('last_name')) {
            $table->addColumn('last_name', 'string', ['limit' => 255, 'null' => true, 'after' => 'first_name']);
        }

        if (!$table->hasColumn('avatar')) {
            $table->addColumn('avatar', 'string', ['limit' => 255, 'null' => true, 'after' => 'phone_number']);
        }

        $table->update();
    }

    public function down(): void
    {
        $table = $this->table(env('PS_TABLE_PREFIX').'manufacturer');
        $table
            ->removeColumn('uuid')
            ->removeColumn('email')
            ->removeColumn('sub')
            ->update();

        $table = $this->table(env('PS_TABLE_PREFIX').'manufacturer_details');
        $table->drop()->save();

        $table = $this->table(env('PS_TABLE_PREFIX') . 'manufacturer_details');

        if ($table->hasColumn('avatar')) {
            $table->removeColumn('avatar');
        }

        if ($table->hasColumn('last_name')) {
            $table->removeColumn('last_name');
        }

        if ($table->hasColumn('first_name')) {
            $table->removeColumn('first_name');
        }

        $table->update();
    }
}
