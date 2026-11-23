<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePersonalWishlists extends AbstractMigration
{
    public function up(): void
    {
        $prefix = (string) env('PS_TABLE_PREFIX', '');
        $this->table($prefix . 'dz_wishlist', ['id' => false, 'primary_key' => ['customer_id']])
            ->addColumn('customer_id', 'integer', ['signed' => false])
            ->addColumn('name', 'string', ['limit' => 128])
            ->addColumn('share_token', 'char', ['limit' => 64])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addIndex(['share_token'], ['unique' => true, 'name' => 'uniq_dz_wishlist_token'])
            ->create();
        $this->table($prefix . 'dz_wishlist_item', ['id' => false, 'primary_key' => ['customer_id', 'product_id', 'product_attribute_id']])
            ->addColumn('customer_id', 'integer', ['signed' => false])
            ->addColumn('product_id', 'integer', ['signed' => false])
            ->addColumn('product_attribute_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('created_at', 'datetime')
            ->create();
    }

    public function down(): void
    {
        $prefix = (string) env('PS_TABLE_PREFIX', '');
        $this->table($prefix . 'dz_wishlist_item')->drop()->save();
        $this->table($prefix . 'dz_wishlist')->drop()->save();
    }
}
