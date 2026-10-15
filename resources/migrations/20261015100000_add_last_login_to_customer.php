<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddLastLoginToCustomer extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table((string) env('PS_TABLE_PREFIX', '') . 'customer');

        if (!$table->hasColumn('last_login')) {
            $table
                ->addColumn('last_login', 'datetime', ['null' => true, 'after' => 'date_upd'])
                ->update();
        }
    }

    public function down(): void
    {
        $table = $this->table((string) env('PS_TABLE_PREFIX', '') . 'customer');

        if ($table->hasColumn('last_login')) {
            $table->removeColumn('last_login')->update();
        }
    }
}
