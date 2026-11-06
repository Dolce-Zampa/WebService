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
use PS\Webservice\Service\Shipping\ShipmentLabelNotificationWorker;

foreach (['PacklinkException', 'PacklinkClient', 'PrestashopShippingClient', 'ShipmentLabelNotificationWorker'] as $class) {
    require_once __DIR__ . '/../src/Service/Shipping/' . $class . '.php';
}

final class ShipmentLabelNotificationWorkerTest extends TestCase
{
    private $db;

    protected function setUp(): void
    {
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->db = $capsule->getConnection();
        $this->db->statement('CREATE TABLE packlink_shipments (id INTEGER PRIMARY KEY, order_id INTEGER UNIQUE, status TEXT, provider_reference TEXT, labels_json TEXT, label_seller_id INTEGER, label_mail_status TEXT, label_mail_error TEXT, label_mail_due_at TEXT, label_mail_sent_at TEXT, updated_at TEXT)');
        $this->db->statement('CREATE TABLE order_detail (id_order INTEGER, product_id INTEGER)');
        $this->db->statement('CREATE TABLE product (id_product INTEGER, id_manufacturer INTEGER)');
        $this->db->table('packlink_shipments')->insert(['id' => 1, 'order_id' => 12, 'status' => 'created', 'provider_reference' => 'PL123', 'labels_json' => '["old"]', 'label_seller_id' => 9, 'label_mail_status' => 'pending']);
        $this->db->table('order_detail')->insert(['id_order' => 12, 'product_id' => 4]);
        $this->db->table('product')->insert(['id_product' => 4, 'id_manufacturer' => 9]);
    }

    private function worker(array $providerResponses, array $shopResponses, array &$history): ShipmentLabelNotificationWorker
    {
        $stack = HandlerStack::create(new MockHandler($shopResponses));
        $stack->push(Middleware::history($history));
        $http = new Client(['handler' => new MockHandler($providerResponses)]);
        return new ShipmentLabelNotificationWorker($this->db, new PacklinkClient($http, 'https://api.packlink.com', 'key'),
            new PrestashopShippingClient(new Client(['handler' => $stack]), 'https://shop.example', 'key', str_repeat('s', 32)),
            $http, ['READY'], ['labels.example']);
    }

    private function readyResponses(string $url = 'https://labels.example/fresh.pdf'): array
    {
        return [new Response(200, [], '{"packlink_reference":"PL123","state":"READY"}'),
            new Response(200, [], json_encode([$url])), new Response(200, [], '%PDF-1.4 test')];
    }

    public function testSendsFreshPdfOnceForSingleSeller(): void
    {
        $history = [];
        $worker = $this->worker($this->readyResponses(), [new Response(200, [], '{"success":true,"data":{"sent":true}}')], $history);
        self::assertSame(1, $worker->run()['sent']);
        $body = json_decode((string) $history[0]['request']->getBody(), true);
        self::assertSame(9, $body['seller_id']);
        self::assertSame('%PDF-1.4 test', base64_decode($body['attachments'][0]));
        self::assertSame('sent', $this->db->table('packlink_shipments')->value('label_mail_status'));
        self::assertSame(0, $worker->run()['sent']);
        self::assertCount(1, $history);
    }

    public function testMultiSellerOrChangedOwnershipIsHeld(): void
    {
        $this->db->table('product')->insert(['id_product' => 5, 'id_manufacturer' => 10]);
        $this->db->table('order_detail')->insert(['id_order' => 12, 'product_id' => 5]);
        $history = [];
        $worker = $this->worker([], [], $history);
        self::assertSame(1, $worker->run()['held']);
        self::assertSame('held', $this->db->table('packlink_shipments')->value('label_mail_status'));
        self::assertCount(0, $history);
    }

    public function testMissingSellerIsHeld(): void
    {
        $this->db->table('packlink_shipments')->update(['label_seller_id' => null]);
        $history = [];
        self::assertSame(1, $this->worker([], [], $history)->run()['held']);
        self::assertCount(0, $history);
    }

    public function testOnlyConfiguredPaidStateAndApprovedHostsCanBeDownloaded(): void
    {
        $history = [];
        $responses = [new Response(200, [], '{"packlink_reference":"PL123","state":"PENDING"}')];
        self::assertSame(1, $this->worker($responses, [], $history)->run()['deferred']);
        $this->db->table('packlink_shipments')->update(['label_mail_due_at' => null]);
        self::assertSame(1, $this->worker($this->readyResponses('https://evil.example/label'), [], $history)->run()['deferred']);
        self::assertCount(0, $history);
    }

    public function testUnconfirmedMailIsNotRetriedAutomatically(): void
    {
        $history = [];
        $worker = $this->worker($this->readyResponses(), [new Response(500, [], '{}')], $history);
        self::assertSame(1, $worker->run()['unknown']);
        self::assertSame('unknown', $this->db->table('packlink_shipments')->value('label_mail_status'));
        self::assertSame(0, $worker->run()['sent']);
        self::assertCount(1, $history);
    }

    public function testInvalidPdfIsDeferredWithoutSendingMail(): void
    {
        $history = [];
        $responses = [new Response(200, [], '{"packlink_reference":"PL123","state":"READY"}'),
            new Response(200, [], '["https://labels.example/fresh.pdf"]'), new Response(200, [], '<html>expired</html>')];
        self::assertSame(1, $this->worker($responses, [], $history)->run()['deferred']);
        self::assertCount(0, $history);
    }
}
