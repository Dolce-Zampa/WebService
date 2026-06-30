<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddReviewProductTable extends AbstractMigration
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

        $table = $this->table(env('PS_TABLE_PREFIX').'product_reviews');
        $table
            ->addColumn('id_product', 'integer')
            ->addColumn('id_customer', 'integer', ['null' => true])
            ->addColumn('id_order', 'integer', ['null' => true])
            ->addColumn('id_manufacturer', 'integer', ['null' => true])
            ->addColumn('rating', 'integer')
            ->addColumn('comment', 'text')
            ->addColumn('status', 'enum', ['values' => ['pending', 'approved', 'rejected'], 'default' => 'pending'])
            ->addTimestamps()
            ->addIndex(['id_product'], ['name' => 'idx_product_reviews_id_product'])
            ->create();
    }
}
