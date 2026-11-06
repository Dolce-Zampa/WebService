<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\Shipping\PacklinkClient;
use PS\Webservice\Service\Shipping\PacklinkDraftFactory;
use PS\Webservice\Service\Shipping\PrestashopShippingClient;
use PS\Webservice\Service\Shipping\ShipmentCreationWorker;
use PS\Webservice\Service\Shipping\ShipmentDraftCreator;

foreach (['PacklinkException','PacklinkClient','PrestashopShippingClient','PacklinkDraftFactory','ShipmentDraftCreator','ShipmentCreationWorker'] as $class) {
    require_once __DIR__ . '/../src/Service/Shipping/' . $class . '.php';
}

final class ShipmentCreationWorkerTest extends TestCase
{
    private $db;
    private array $config;
    private array $order;

    protected function setUp(): void
    {
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->db = $capsule->getConnection();
        $this->db->statement('CREATE TABLE packlink_shipments (id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL UNIQUE, status TEXT NOT NULL, provider_reference TEXT UNIQUE, label_seller_id INTEGER, last_error TEXT, attempt_count INTEGER DEFAULT 0, next_attempt_at TEXT, created_at TEXT, updated_at TEXT)');
        $address = ['name' => 'A', 'surname' => 'B', 'street1' => 'Street', 'zip_code' => '00100', 'city' => 'Roma', 'country' => 'IT', 'phone' => '123', 'email' => 'a@example.test'];
        $this->config = ['since' => '2026-11-05 12:00:00', 'eligible_states' => [2], 'carriers' => ['5' => ['service_id' => 'service']],
            'draft' => ['user_id' => 'user', 'client_id' => 'client', 'platform' => 'PL', 'platform_country' => 'IT', 'source' => 'shop', 'from' => $address],
            'package' => ['weight' => 1, 'width' => 10, 'height' => 10, 'length' => 10], 'content' => 'Products'];
        $this->order = ['id' => 12, 'date_add' => '2026-11-05 12:00:01', 'current_state' => 2, 'is_virtual' => false, 'carrier_reference' => 5, 'to' => $address, 'currency' => 'EUR', 'content_value' => 12.0, 'weight_unit' => 'kg', 'dimension_unit' => 'cm', 'label_seller_id' => 9, 'items' => [['quantity' => 1, 'weight' => 1.5, 'width' => 2, 'height' => 3, 'length' => 4]]];
    }

    private function worker(array $order, MockHandler $packlink): ShipmentCreationWorker
    {
        $shopResponses = new MockHandler([
            new Response(200, [], '{"success":true,"data":{"order_ids":[12]}}'),
            new Response(200, [], json_encode(['success' => true, 'data' => ['order' => $order]])),
        ]);
        $client = new PacklinkClient(new Client(['handler' => $packlink]), 'https://api.packlink.com', 'key');
        return new ShipmentCreationWorker($this->db, new PrestashopShippingClient(new Client(['handler' => $shopResponses]), 'https://shop.example', 'key', str_repeat('s', 32)), new ShipmentDraftCreator($this->db, $client), new PacklinkDraftFactory(), $this->config);
    }

    public function testEligiblePostCutoverOrderCreatesOneDraft(): void
    {
        $packlink = new MockHandler([new Response(200, [], '{"reference":"PL123"}')]);
        $result = $this->worker($this->order, $packlink)->run();
        self::assertSame(1, $result['created']);
        self::assertSame('PL123', $this->db->table('packlink_shipments')->where('order_id', 12)->value('provider_reference'));
        self::assertSame(9, $this->db->table('packlink_shipments')->where('order_id', 12)->value('label_seller_id'));
    }

    public function testMultipleItemsUseTotalWeightAndConfiguredGroupedDimensions(): void
    {
        $this->order['items'][0]['quantity'] = 2;
        $sent = null;
        $packlink = new MockHandler([static function ($request) use (&$sent): Response {
            $sent = json_decode((string) $request->getBody(), true);
            return new Response(200, [], '{"reference":"PL123"}');
        }]);
        self::assertSame(1, $this->worker($this->order, $packlink)->run()['created']);
        self::assertSame(['weight' => 3, 'width' => 10, 'height' => 10, 'length' => 10], $sent['packages'][0]);
    }

    public function testProviderFailureLeavesAnAmbiguousShipmentForManualRecovery(): void
    {
        $packlink = new MockHandler([new Response(503, [], '{}')]);
        self::assertSame(1, $this->worker($this->order, $packlink)->run()['unknown']);
        self::assertSame('unknown', $this->db->table('packlink_shipments')->where('order_id', 12)->value('status'));
    }

    public function testOrderBeforeCutoverIsNeverSentToPacklinkEvenIfShopListsIt(): void
    {
        $this->order['date_add'] = '2026-11-05 11:59:59';
        $packlink = new MockHandler([]);
        $result = $this->worker($this->order, $packlink)->run();
        self::assertSame(1, $result['deferred']);
        self::assertSame('pending', $this->db->table('packlink_shipments')->where('order_id', 12)->value('status'));
    }

    public function testPickupWithoutSelectionCannotSendDraft(): void
    {
        $this->config['carriers']['5']['pickup'] = true;
        $packlink = new MockHandler([]);
        $result = $this->worker($this->order, $packlink)->run();
        self::assertSame(1, $result['deferred']);
        self::assertSame('pending', $this->db->table('packlink_shipments')->where('order_id', 12)->value('status'));
    }
}
