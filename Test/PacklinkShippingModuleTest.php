<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PacklinkShippingModuleTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__ . '/fixtures/packlink_shipping_prestashop.php';
        Db::$instance = new Db();
        Db::getInstance()->execute("INSERT INTO orders VALUES (1, 2, '2026-11-05 12:00:01'), (2, 2, '2026-11-05 11:59:59')");
        Db::getInstance()->execute('INSERT INTO product VALUES (1, 0)');
        Db::getInstance()->execute('INSERT INTO order_detail VALUES (1, 1), (2, 1)');
    }

    public function testShippingEndpointRequiresSeparateSecret(): void
    {
        putenv('PACKLINK_SHOP_SECRET=' . str_repeat('s', 32));
        $_SERVER['HTTP_X_WS_KEY'] = 'test-key';
        $_SERVER['HTTP_X_PACKLINK_SHIPPING_SECRET'] = 'wrong';
        $this->expectException(MlabFactoryApiException::class);
        (new TestPacklinkShippingController())->authenticateForTest();
    }

    public function testCandidateScanEnforcesCutover(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['since' => '2026-11-05 12:00:00', 'states' => '2'];
        self::assertSame(['order_ids' => [1]], (new TestPacklinkShippingController())->invoke());
    }

    public function testPickupMappingUsesLiveCarrierIdRatherThanReference(): void
    {
        $json = pSQL(json_encode(['cartId' => '7', 'carrierReferenceId' => '42', 'dropOff' => ['id' => 'POINT']]));
        Db::getInstance()->execute("INSERT INTO packlink_entity VALUES ('CartCarrierDropOffMapping', '7', '42', '" . $json . "')");
        $controller = new TestPacklinkShippingController();
        $method = new ReflectionMethod(webserviceapipacklinkshippingModuleFrontController::class, 'getDropOffId');
        self::assertSame('POINT', $method->invoke($controller, 7, 42));
    }

    public function testRepeatedSnapshotAddsHistoryOnceAndStaleRankIsIgnored(): void
    {
        Db::getInstance()->execute("INSERT INTO packlink_shipments (order_id, status, provider_reference) VALUES (1, 'created', 'PL1')");
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $controller = new TestPacklinkShippingController();
        $controller->payload = ['order_id' => 1, 'provider_reference' => 'PL1', 'provider_state' => 'READY', 'rank' => 2, 'order_state_id' => 9, 'eligible_states' => [2], 'tracking_number' => 'T123', 'tracking_url' => 'https://tracking.example/x', 'labels' => ['https://labels.example/x']];
        self::assertTrue($controller->invoke()['applied']);
        self::assertTrue($controller->invoke()['applied']);
        $controller->payload['rank'] = 1;
        $controller->payload['order_state_id'] = 8;
        self::assertSame('older_state', $controller->invoke()['reason']);
        self::assertSame(1, (int) Db::getInstance()->getRow('SELECT COUNT(*) AS n FROM history')['n']);
        self::assertSame(9, (int) Db::getInstance()->getRow('SELECT current_state FROM orders WHERE id_order = 1')['current_state']);
    }

    public function testManualOrderStateChangeIsNotOverwritten(): void
    {
        Db::getInstance()->execute("INSERT INTO packlink_shipments (order_id, status, provider_reference, last_state_rank, last_order_state) VALUES (1, 'created', 'PL1', 1, 8)");
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $controller = new TestPacklinkShippingController();
        $controller->payload = ['order_id' => 1, 'provider_reference' => 'PL1', 'provider_state' => 'READY', 'rank' => 2, 'order_state_id' => 9, 'eligible_states' => [2]];
        $this->expectException(MlabFactoryApiException::class);
        $controller->invoke();
    }
}
