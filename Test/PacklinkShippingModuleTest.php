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
        Db::getInstance()->execute('INSERT INTO product VALUES (1, 0, 9)');
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

    public function testSenderComesFromArtisanProfileAndResolvesCountryIso(): void
    {
        $db = Db::getInstance();
        $db->execute("INSERT INTO manufacturer VALUES (9, 'Bottega', 'seller@example.test')");
        $db->execute("INSERT INTO manufacturer_details VALUES (9, 'Anna', 'Rossi', 'Via Roma 1', 'Roma', 'Italia', '00100', '123456')");
        $db->execute("INSERT INTO country VALUES (1, 'IT')");
        $db->execute("INSERT INTO country_lang VALUES (1, 'Italia')");
        $method = new ReflectionMethod(webserviceapipacklinkshippingModuleFrontController::class, 'sellerAddress');
        $address = $method->invoke(new TestPacklinkShippingController(), 9);
        self::assertSame('Via Roma 1', $address['street1']);
        self::assertSame('IT', $address['country']);
        self::assertSame('seller@example.test', $address['email']);
        $db->execute("UPDATE manufacturer_details SET phone_number = '' WHERE id_manufacturer = 9");
        self::assertNull($method->invoke(new TestPacklinkShippingController(), 9));
    }

    public function testSenderWithAmbiguousCountryOrDuplicateProfileIsRejected(): void
    {
        $db = Db::getInstance();
        $db->execute("INSERT INTO manufacturer VALUES (9, 'Bottega', 'seller@example.test')");
        $db->execute("INSERT INTO manufacturer_details VALUES (9, 'Anna', 'Rossi', 'Via Roma 1', 'Roma', 'Sconosciuto', '00100', '123456')");
        $method = new ReflectionMethod(webserviceapipacklinkshippingModuleFrontController::class, 'sellerAddress');
        self::assertNull($method->invoke(new TestPacklinkShippingController(), 9));
        $db->execute("INSERT INTO manufacturer_details VALUES (9, 'Anna', 'Rossi', 'Via Roma 2', 'Roma', 'IT', '00100', '123456')");
        self::assertNull($method->invoke(new TestPacklinkShippingController(), 9));
    }

    public function testOrderDataIncludesSellerAddressAndProductCategory(): void
    {
        $db = Db::getInstance();
        $db->execute("INSERT INTO manufacturer VALUES (9, 'Bottega', 'seller@example.test')");
        $db->execute("INSERT INTO manufacturer_details VALUES (9, 'Anna', 'Rossi', 'Via Roma 1', 'Roma', 'Italia', '00100', '123456')");
        $db->execute("INSERT INTO country VALUES (1, 'IT')");
        $db->execute("INSERT INTO country_lang VALUES (1, 'Italia')");
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['id_order' => 1];
        $order = (new TestPacklinkShippingController())->invoke()['order'];
        self::assertSame(9, $order['label_seller_id']);
        self::assertSame('Via Roma 1', $order['from']['street1']);
        self::assertSame('Collari', $order['items'][0]['category']);
        self::assertSame(1.5, $order['items'][0]['weight']);
        $db->execute('INSERT INTO product VALUES (2, 0, 10)');
        $db->execute('INSERT INTO order_detail VALUES (1, 2)');
        $order = (new TestPacklinkShippingController())->invoke()['order'];
        self::assertNull($order['label_seller_id']);
        self::assertNull($order['from']);
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
    public function testLabelMailContainsPdfAndRechecksSeller(): void
    {
        Db::getInstance()->execute("INSERT INTO packlink_shipments (order_id, status, provider_reference, label_seller_id, label_mail_status) VALUES (1, 'created', 'PL1', 9, 'sending')");
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $controller = new TestPacklinkShippingController();
        $controller->payload = ['action' => 'send_label', 'order_id' => 1, 'seller_id' => 9, 'attachments' => [base64_encode('%PDF-1.4 test')]];
        self::assertTrue($controller->invoke()['sent']);
        self::assertSame('seller@example.test', Mail::$calls[0][4]);
        self::assertSame('%PDF-1.4 test', Mail::$calls[0][8][0]['content']);
        Db::getInstance()->execute('UPDATE product SET id_manufacturer = 10 WHERE id_product = 1');
        $this->expectException(MlabFactoryApiException::class);
        $controller->invoke();
    }

    public function testLabelMailRejectsNonPdf(): void
    {
        Db::getInstance()->execute("INSERT INTO packlink_shipments (order_id, status, provider_reference, label_seller_id, label_mail_status) VALUES (1, 'created', 'PL1', 9, 'sending')");
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $controller = new TestPacklinkShippingController();
        $controller->payload = ['action' => 'send_label', 'order_id' => 1, 'seller_id' => 9, 'attachments' => [base64_encode('not a PDF')]];
        $this->expectException(MlabFactoryApiException::class);
        $controller->invoke();
    }

}
