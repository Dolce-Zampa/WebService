<?php
define('_PS_VERSION_', '9.2.0');
class ModuleFrontController { public $context; public $module; }
class Hook { public static $registered = true; public static function isModuleRegisteredOnHook($module, $hook, $shop) { return self::$registered; } }
class Db {
    private static $instance;
    public $pdo;
    public static function getInstance() { return self::$instance ??= new self(); }
    public function __construct() {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE addresses (id INTEGER PRIMARY KEY, city TEXT)');
        $this->pdo->exec('CREATE TABLE carts (id INTEGER PRIMARY KEY, carrier INTEGER)');
        $this->pdo->exec('INSERT INTO carts VALUES (42, 19)');
    }
    public function execute($sql) { return $this->pdo->exec($sql === 'START TRANSACTION' ? 'BEGIN' : $sql) !== false; }
}
class Validate { public static function isLoadedObject($object) { return !empty($object->id); } }
class Country {
    public function __construct($id = 0) {}
    public static function getByIso($iso) { return $iso === 'IT' ? 10 : 0; }
}
class Configuration { public static function get($key) { return 10; } }
class Currency { public function __construct($id = 0) {} }
class Language { public function __construct($id = 0) {} }
class Customer {
    public $id = 0; public $firstname = ''; public $lastname = '';
    public function __construct($id = 0) { $this->id = $id; }
}
class Address {
    public $id = 0; public $id_customer = 0; public $alias = ''; public $firstname = ''; public $lastname = '';
    public $company = ''; public $vat_number = ''; public $dni = ''; public $address1 = ''; public $address2 = '';
    public $postcode = ''; public $city = ''; public $id_country = 0; public $id_state = 0; public $phone = '';
    public $phone_mobile = ''; public $other = '';
    public static function getCountryAndState($id) { return ['id_country' => 10]; }
    public function add() {
        Db::getInstance()->pdo->exec("INSERT INTO addresses (city) VALUES ('temporary')");
        $this->id = (int) Db::getInstance()->pdo->lastInsertId();
        return true;
    }
}
class Cart {
    const ONLY_SHIPPING = 2;
    public static $failQuote = false;
    public $id = 42; public $id_customer = 0; public $id_guest = 8; public $id_currency = 1; public $id_lang = 1;
    public $id_carrier = 19; public $id_address_delivery = 0; public $id_address_invoice = 0;
    public function setDeliveryOption($options) { Db::getInstance()->pdo->exec('UPDATE carts SET carrier = ' . (int) $this->id_carrier . ' WHERE id = 42'); }
    public function getDeliveryOptionList() { return [$this->id_address_delivery => [$this->id_carrier . ',' => []]]; }
    public function getOrderTotal($tax, $type) { if (self::$failQuote) throw new RuntimeException('quote failed'); return 7.80; }
}
