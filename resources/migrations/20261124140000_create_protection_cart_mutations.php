<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** A persisted intent prevents a timeout from being treated as a failed write. */
final class CreateProtectionCartMutations extends AbstractMigration
{
    public function up(): void
    {
        $name = (string) env('PS_TABLE_PREFIX', '') . 'protection_cart_mutations';
        $this->table($name, ['id' => false, 'primary_key' => ['cart_id']])
            ->addColumn('cart_id', 'integer', ['signed' => false])
            ->addColumn('operation_id', 'char', ['limit' => 32])
            ->addColumn('operation', 'string', ['limit' => 32])
            ->addColumn('created_at', 'datetime')
            ->create();
    }

    public function down(): void
    {
        $name = (string) env('PS_TABLE_PREFIX', '') . 'protection_cart_mutations';
        if ($this->hasTable($name)) $this->table($name)->drop()->save();
    }
}
