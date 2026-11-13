<?php
declare(strict_types=1);

use Phinx\Db\Adapter\SQLiteAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

require_once __DIR__ . '/../resources/migrations/20261112180000_create_inpost_points.php';

final class InpostMigrationTest extends TestCase
{
    public function test_primary_keys_and_required_fields_are_not_nullable(): void
    {
        $connection = new PDO('sqlite::memory:');
        $adapter = new SQLiteAdapter(['name' => ':memory:'], null, new NullOutput());
        $adapter->setConnection($connection);
        $migration = new CreateInpostPoints('test', 20261112180000);
        $migration->setAdapter($adapter);
        $migration->up();

        $prefix = (string) env('PS_TABLE_PREFIX', '');
        foreach ([
            'inpost_points' => ['point_id', 'name', 'type', 'address', 'city', 'postal_code'],
            'inpost_sync' => ['id', 'point_count'],
        ] as $table => $requiredColumns) {
            $columns = $connection->query('PRAGMA table_info("' . $prefix . $table . '")')->fetchAll(PDO::FETCH_ASSOC);
            $byName = array_column($columns, null, 'name');
            foreach ($requiredColumns as $column) {
                self::assertSame(1, (int) $byName[$column]['notnull'], $table . '.' . $column . ' must be NOT NULL');
            }
        }
        self::assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM "' . $prefix . 'inpost_sync" WHERE id = 1')->fetchColumn());
    }
}
