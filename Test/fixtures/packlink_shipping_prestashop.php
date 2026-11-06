<?php

define('_DB_PREFIX_', '');
class ModuleFrontController {}
class Module { public static function isEnabled($name) { return false; } }
class Configuration { public static function get($key) { return $key === 'PACKLINK_EXTERNAL_SHIPMENTS_SINCE' ? '2026-11-05 12:00:00' : ''; } }
class Tools { public static function getValue($key, $default = null) { return $_GET[$key] ?? $default; } }
class PrestaShopDatabaseException extends Exception {}
class PrestaShopException extends Exception {}
function pSQL($value, $htmlOK = false) { return str_replace("'", "''", (string) $value); }
class Validate {
    public static function isLoadedObject($object) { return $object && $object->id > 0; }
    public static function isTrackingNumber($value) { return is_string($value) && !preg_match('/[\\r\\n]/', $value); }
}
class Db {
    public static $instance;
    public $pdo;
    public function __construct() {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE webservice_account (`key` TEXT, active INTEGER)');
        $this->pdo->exec("INSERT INTO webservice_account VALUES ('test-key', 1)");
        $this->pdo->exec('CREATE TABLE orders (id_order INTEGER PRIMARY KEY, current_state INTEGER, date_add TEXT)');
        $this->pdo->exec('CREATE TABLE product (id_product INTEGER PRIMARY KEY, is_virtual INTEGER)');
        $this->pdo->exec('CREATE TABLE order_detail (id_order INTEGER, product_id INTEGER)');
        $this->pdo->exec('CREATE TABLE packlink_shipments (order_id INTEGER PRIMARY KEY, status TEXT, provider_reference TEXT, last_state_rank INTEGER, last_order_state INTEGER, provider_state TEXT, tracking_number TEXT, tracking_url TEXT, labels_json TEXT, updated_at TEXT)');
        $this->pdo->exec('CREATE TABLE packlink_entity (type TEXT, index_1 TEXT, index_2 TEXT, data TEXT)');
        $this->pdo->exec('CREATE TABLE history (order_id INTEGER, state INTEGER)');
    }
    public static function getInstance() { return self::$instance; }
    public function execute($sql) { if ($sql === 'START TRANSACTION') { $sql = 'BEGIN TRANSACTION'; } return $this->pdo->exec($sql) !== false; }
    public function executeS($sql, $array = true, $useCache = true) { $sql = str_replace(' FOR UPDATE', '', $sql); return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    public function getRow($sql) { $sql = str_replace(' FOR UPDATE', '', $sql); return $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC); }
    public function update($table, $values, $where, $limit = 0, $nulls = false) {
        $parts = array();
        foreach ($values as $key => $value) { $parts[] = '`' . $key . '` = ' . ($value === null ? 'NULL' : "'" . $value . "'"); }
        return $this->pdo->exec('UPDATE `' . $table . '` SET ' . implode(', ', $parts) . ' WHERE ' . $where) !== false;
    }
}
class OrderState { public $id; public function __construct($id) { $this->id = $id; } }
class Order {
    public $id; public $current_state;
    public function __construct($id) { $this->id = $id; $this->current_state = (int) Db::getInstance()->getRow('SELECT current_state FROM orders WHERE id_order = ' . (int) $id)['current_state']; }
    public function getWsShippingNumber() { return Db::getInstance()->getRow('SELECT tracking_number FROM packlink_shipments WHERE order_id = ' . $this->id)['tracking_number']; }
    public function setWsShippingNumber($number) { return Db::getInstance()->update('packlink_shipments', ['tracking_number' => pSQL($number)], 'order_id = ' . $this->id); }
    public function hasInvoice() { return false; }
}
class OrderHistory {
    public $id_order; private $state;
    public function changeIdOrderState($state, $order, $existing) { $this->state = $state; Db::getInstance()->execute('UPDATE orders SET current_state = ' . (int) $state . ' WHERE id_order = ' . (int) $order->id); }
    public function add() { return Db::getInstance()->execute('INSERT INTO history VALUES (' . (int) $this->id_order . ', ' . (int) $this->state . ')'); }
}
require __DIR__ . '/../../resources/prestashop-modules/webserviceapi/controllers/front/packlinkshipping.php';
class TestPacklinkShippingController extends webserviceapipacklinkshippingModuleFrontController {
    public $payload = [];
    protected function getJsonPayload() { return $this->payload; }
    public function invoke() { return $this->handleRequest(); }
    public function authenticateForTest() { return $this->authenticate(); }
}
