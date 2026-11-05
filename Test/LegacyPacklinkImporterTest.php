<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\Shipping\LegacyPacklinkImporter;

require_once __DIR__ . '/../src/Service/Shipping/LegacyPacklinkImporter.php';

final class LegacyPacklinkImporterTest extends TestCase
{
    private $db;

    protected function setUp(): void
    {
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->db = $capsule->getConnection();
        $this->db->statement('CREATE TABLE packlink_entity (id INTEGER PRIMARY KEY, type TEXT NOT NULL, data TEXT NOT NULL)');
        $this->db->statement('CREATE TABLE packlink_shipments (id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL UNIQUE, status TEXT NOT NULL, provider_reference TEXT UNIQUE, created_at TEXT, updated_at TEXT)');
    }

    public function testImportsReferencesOnce(): void
    {
        $this->db->table('packlink_entity')->insert(['type' => 'OrderShipmentDetails', 'data' => '{"orderId":"12","reference":"PL123"}']);
        $importer = new LegacyPacklinkImporter($this->db);
        self::assertSame(1, $importer->import());
        self::assertSame(0, $importer->import());
        self::assertSame('PL123', $this->db->table('packlink_shipments')->where('order_id', 12)->value('provider_reference'));
    }

    public function testConflictAbortsEntireImport(): void
    {
        $this->db->table('packlink_entity')->insert([
            ['type' => 'OrderShipmentDetails', 'data' => '{"orderId":"12","reference":"PL123"}'],
            ['type' => 'OrderShipmentDetails', 'data' => '{"orderId":"13","reference":"PL123"}'],
        ]);
        try {
            (new LegacyPacklinkImporter($this->db))->import();
            self::fail('Conflicting legacy records must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame(0, $this->db->table('packlink_shipments')->count());
        }
    }

    public function testMissingReferenceStopsImport(): void
    {
        $this->db->table('packlink_entity')->insert(['type' => 'OrderShipmentDetails', 'data' => '{"orderId":"12"}']);
        $this->expectException(RuntimeException::class);
        (new LegacyPacklinkImporter($this->db))->import();
    }
}
