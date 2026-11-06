<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Commands\RecoverPacklinkShipmentCommand;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__ . '/../src/Commands/RecoverPacklinkShipmentCommand.php';

final class RecoverPacklinkShipmentCommandTest extends TestCase
{
    private $db;
    private $tester;

    protected function setUp(): void
    {
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->db = $capsule->getConnection();
        $this->db->statement('CREATE TABLE packlink_shipments (order_id INTEGER PRIMARY KEY, status TEXT, provider_reference TEXT, last_error TEXT, next_attempt_at TEXT, sync_state TEXT, sync_due_at TEXT, updated_at TEXT)');
        $this->db->table('packlink_shipments')->insert(['order_id' => 12, 'status' => 'unknown']);
        $this->tester = new CommandTester(new RecoverPacklinkShipmentCommand($this->db, new Client(['handler' => new MockHandler([])])));
    }

    public function testRecoveryRequiresWorkerStoppedAcknowledgement(): void
    {
        self::assertSame(1, $this->tester->execute(['order-id' => '12', '--confirm-no-draft' => true]));
        self::assertSame('unknown', $this->db->table('packlink_shipments')->value('status'));
    }

    public function testExplicitManualRecoveryReschedulesUnknownDraft(): void
    {
        self::assertSame(0, $this->tester->execute(['order-id' => '12', '--confirm-no-draft' => true, '--worker-stopped' => true]));
        self::assertSame('pending', $this->db->table('packlink_shipments')->value('status'));
        self::assertNotNull($this->db->table('packlink_shipments')->value('next_attempt_at'));
    }
}
