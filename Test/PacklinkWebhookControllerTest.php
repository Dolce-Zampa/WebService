<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Http\Controller\PacklinkWebhookController;

require_once __DIR__ . '/../src/Service/Shipping/PacklinkWebhook.php';
require_once __DIR__ . '/../src/Http/Controller/PacklinkWebhookController.php';

final class PacklinkWebhookControllerTest extends TestCase
{
    private $db;
    private string $secret;
    private string $body = '{"event":"shipment.tracking.update","datetime":"2026-01-01","data":{"shipment_reference":"PL123"}}';

    protected function setUp(): void
    {
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->db = $capsule->getConnection();
        $this->db->statement('CREATE TABLE packlink_shipments (id INTEGER PRIMARY KEY, status TEXT, provider_reference TEXT, sync_due_at TEXT, updated_at TEXT)');
        $this->db->table('packlink_shipments')->insert(['status' => 'created', 'provider_reference' => 'PL123']);
        $this->secret = str_repeat('x', 32);
    }

    public function testValidRepeatedWebhookSchedulesReconciliation(): void
    {
        $controller = new PacklinkWebhookController($this->db, $this->secret);
        $request = new ServerRequest('POST', 'https://service.example/api/webhooks/packlink', ['X-Packlink-Webhook-Secret' => $this->secret], $this->body);
        self::assertSame(202, $controller->handle($request, new Response())->getStatusCode());
        self::assertNotNull($this->db->table('packlink_shipments')->value('sync_due_at'));
        $request->getBody()->rewind();
        self::assertSame(202, $controller->handle($request, new Response())->getStatusCode());
    }

    public function testInvalidSecretCannotSchedule(): void
    {
        $controller = new PacklinkWebhookController($this->db, $this->secret);
        $request = new ServerRequest('POST', 'https://service.example/api/webhooks/packlink', ['X-Packlink-Webhook-Secret' => 'wrong'], $this->body);
        self::assertSame(401, $controller->handle($request, new Response())->getStatusCode());
        self::assertNull($this->db->table('packlink_shipments')->value('sync_due_at'));
    }
}
