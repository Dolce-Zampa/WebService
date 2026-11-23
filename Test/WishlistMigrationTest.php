<?php

declare(strict_types=1);

use Phinx\Config\FeatureFlags;
use Phinx\Db\Adapter\SQLiteAdapter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../resources/migrations/20261120130000_create_personal_wishlists.php';

final class WishlistMigrationTest extends TestCase
{
    public function test_migration_creates_non_nullable_keys_and_reverses_cleanly(): void
    {
        $previousNullDefault = FeatureFlags::$columnNullDefault;
        FeatureFlags::$columnNullDefault = true;
        try {
            $pdo = new PDO('sqlite::memory:');
            $adapter = new SQLiteAdapter([]);
            $adapter->setConnection($pdo);
            $migration = new CreatePersonalWishlists('test', 20261120130000);
            $migration->setAdapter($adapter);
            $migration->up();

            $prefix = (string) env('PS_TABLE_PREFIX', '');
            foreach ([
                'dz_wishlist' => ['customer_id', 'name', 'share_token', 'created_at', 'updated_at'],
                'dz_wishlist_item' => ['customer_id', 'product_id', 'product_attribute_id', 'created_at'],
            ] as $table => $requiredColumns) {
                $columns = $pdo->query('PRAGMA table_info(' . $pdo->quote($prefix . $table) . ')')->fetchAll(PDO::FETCH_ASSOC);
                $byName = array_column($columns, null, 'name');
                foreach ($requiredColumns as $column) {
                    self::assertArrayHasKey($column, $byName);
                    self::assertSame(1, (int) $byName[$column]['notnull'], "$table.$column must be NOT NULL");
                }
            }

            $itemColumns = $pdo->query('PRAGMA table_info(' . $pdo->quote($prefix . 'dz_wishlist_item') . ')')->fetchAll(PDO::FETCH_ASSOC);
            $itemColumns = array_column($itemColumns, null, 'name');
            self::assertSame(1, (int) $itemColumns['customer_id']['pk']);
            self::assertSame(2, (int) $itemColumns['product_id']['pk']);
            self::assertSame(3, (int) $itemColumns['product_attribute_id']['pk']);

            $migration->down();
            self::assertFalse($adapter->hasTable($prefix . 'dz_wishlist'));
            self::assertFalse($adapter->hasTable($prefix . 'dz_wishlist_item'));
        } finally {
            FeatureFlags::$columnNullDefault = $previousNullDefault;
        }
    }
}
