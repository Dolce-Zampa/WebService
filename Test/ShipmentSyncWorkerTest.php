<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\Shipping\PacklinkClient;
use PS\Webservice\Service\Shipping\PrestashopShippingClient;
use PS\Webservice\Service\Shipping\ShipmentSyncWorker;

foreach (['PacklinkException','PacklinkClient','PrestashopShippingClient','ShipmentSyncWorker'] as $class) {
    require_once __DIR__ . '/../src/Service/Shipping/' . $class . '.php';
}

final class ShipmentSyncWorkerTest extends TestCase
{
    private $db;

    protected function setUp(): void
    {
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->db = $capsule->getConnection();
        $this->db->statement('CREATE TABLE packlink_shipments (id INTEGER PRIMARY KEY, order_id INTEGER UNIQUE, status TEXT, provider_reference TEXT UNIQUE, sync_state TEXT, sync_attempt_count INTEGER DEFAULT 0, sync_due_at TEXT, last_sync_error TEXT, last_synced_at TEXT, updated_at TEXT)');
        $this->db->table('packlink_shipments')->insert(['order_id' => 12, 'status' => 'created', 'provider_reference' => 'PL123', 'sync_state' => 'idle']);
    }

    public function testPollUsesCurrentProviderDataAndConfiguredState(): void
    {
        $history = [];
        $shopStack = HandlerStack::create(new MockHandler([new Response(200, [], '{"success":true,"data":{"applied":true}}')]));
        $shopStack->push(Middleware::history($history));
        $provider = new MockHandler([
            new Response(200, [], '{"packlink_reference":"PL123","state":"READY","trackings":["T123"],"tracking_url":"https://tracking.example/T123"}'),
            new Response(200, [], '["https://labels.example/1.pdf"]'),
        ]);
        $worker = new ShipmentSyncWorker($this->db, new PacklinkClient(new Client(['handler' => $provider]), 'https://api.packlink.com', 'key'),
            new PrestashopShippingClient(new Client(['handler' => $shopStack]), 'https://shop.example', 'key', str_repeat('s', 32)),
            ['READY' => ['order_state_id' => 9, 'rank' => 2, 'labels' => true]], [2], 300);
        self::assertSame(['synced' => 1, 'failed' => 0], $worker->run());
        $snapshot = json_decode((string) $history[0]['request']->getBody(), true);
        self::assertSame(9, $snapshot['order_state_id']);
        self::assertSame('T123', $snapshot['tracking_number']);
        self::assertSame(['https://labels.example/1.pdf'], $snapshot['labels']);
        self::assertSame('idle', $this->db->table('packlink_shipments')->value('sync_state'));
    }

    public function testUnknownStateSchedulesRetryWithoutPostingToShop(): void
    {
        $provider = new MockHandler([new Response(200, [], '{"packlink_reference":"PL123","state":"UNKNOWN"}')]);
        $shop = new MockHandler([]);
        $worker = new ShipmentSyncWorker($this->db, new PacklinkClient(new Client(['handler' => $provider]), 'https://api.packlink.com', 'key'),
            new PrestashopShippingClient(new Client(['handler' => $shop]), 'https://shop.example', 'key', str_repeat('s', 32)),
            ['READY' => ['order_state_id' => 9, 'rank' => 2]], [2]);
        self::assertSame(['synced' => 0, 'failed' => 1], $worker->run());
        self::assertSame(1, $this->db->table('packlink_shipments')->value('sync_attempt_count'));
        self::assertSame('state_unmapped', $this->db->table('packlink_shipments')->value('last_sync_error'));
        self::assertSame(['synced' => 0, 'failed' => 0], $worker->run());
    }
}
