<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\Shipping\PacklinkClient;
use PS\Webservice\Service\Shipping\ShipmentDraftCreator;

require_once __DIR__ . '/../src/Service/Shipping/PacklinkException.php';
require_once __DIR__ . '/../src/Service/Shipping/PacklinkClient.php';
require_once __DIR__ . '/../src/Service/Shipping/ShipmentDraftCreator.php';

final class ShipmentDraftCreatorTest extends TestCase
{
    private $db;

    protected function setUp(): void
    {
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->db = $capsule->getConnection();
        $this->db->statement('CREATE TABLE packlink_shipments (id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL UNIQUE, status TEXT NOT NULL, provider_reference TEXT UNIQUE, label_seller_id INTEGER, last_error TEXT, created_at TEXT, updated_at TEXT)');
    }

    public function testOnlyOneCreateIsSentForRepeatedOrder(): void
    {
        $mock = new MockHandler([new Response(201, [], '{"reference":"PL-123"}')]);
        $service = new ShipmentDraftCreator($this->db, new PacklinkClient(new Client(['handler' => $mock]), 'https://api.packlink.com', 'key'));
        self::assertSame('created', $service->create(12, ['content' => 'order']));
        self::assertSame('created', $service->create(12, ['content' => 'order']));
        self::assertSame('PL-123', $this->db->table('packlink_shipments')->where('order_id', 12)->value('provider_reference'));
        self::assertSame(0, $mock->count());
    }

    public function testAmbiguousFailureIsNeverResentAutomatically(): void
    {
        $mock = new MockHandler([new Response(503, [], 'unavailable')]);
        $service = new ShipmentDraftCreator($this->db, new PacklinkClient(new Client(['handler' => $mock]), 'https://api.packlink.com', 'key'));
        self::assertSame('unknown', $service->create(12, []));
        self::assertSame('unknown', $service->create(12, []));
        self::assertSame('create_outcome_unknown', $this->db->table('packlink_shipments')->where('order_id', 12)->value('last_error'));
        self::assertSame(0, $mock->count());
    }

    public function testInterleavedWorkerCannotCreateWhileProviderCallIsInFlight(): void
    {
        $secondResult = null;
        $service = null;
        $mock = new MockHandler([
            static function () use (&$service, &$secondResult): Response {
                $secondResult = $service->create(12, ['content' => 'order']);
                return new Response(201, [], '{"reference":"PL-123"}');
            },
        ]);
        $service = new ShipmentDraftCreator($this->db, new PacklinkClient(new Client(['handler' => $mock]), 'https://api.packlink.com', 'key'));
        self::assertSame('created', $service->create(12, ['content' => 'order']));
        self::assertSame('creating', $secondResult);
        self::assertSame(1, $this->db->table('packlink_shipments')->count());
    }

    public function testAnotherWorkerCannotResendAnInFlightDraft(): void
    {
        $this->db->table('packlink_shipments')->insert(['order_id' => 12, 'status' => 'creating']);
        $mock = new MockHandler([]);
        $service = new ShipmentDraftCreator($this->db, new PacklinkClient(new Client(['handler' => $mock]), 'https://api.packlink.com', 'key'));
        self::assertSame('creating', $service->create(12, []));
    }
}
