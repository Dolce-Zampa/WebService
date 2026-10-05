<?php
// Minimal PrestaShop runtime, loaded only inside the isolated serialization test.
define('_DB_PREFIX_', 'ps_');

class Cart
{
    const ONLY_PRODUCTS = 1;
    const ONLY_SHIPPING = 2;
    const BOTH = 3;
    public $id = 10;
    public $id_customer = 12;
    public $id_guest = 0;
    public $id_currency = 1;
    public $id_lang = 2;
    public $id_shop = 3;
    public $id_address_delivery = 4;
    public $id_address_invoice = 4;
    public $id_carrier = 0;
    public $secure_key = 'test';
    public $products = [];
    public function getProducts() { return $this->products; }
    public function getCartRules($filter, $autoAdd) { return []; }
    public function getOrderTotal($tax, $type) { return $type === self::ONLY_SHIPPING ? 6.1 : 40.0; }
}

class CartRule { const FILTER_ACTION_ALL = 1; }
class Product
{
    public static function getCover($id) { return ['id_image' => 100 + $id]; }
}
class Db
{
    public static $instance;
    public $metadata = [];
    public $queries = [];
    public static function getInstance() { return self::$instance ?? (self::$instance = new self()); }
    public function executeS($sql)
    {
        $this->queries[] = $sql;
        if (strpos($sql, 'product_attribute_image') !== false) {
            return [['id_image' => 999]];
        }
        return $this->metadata;
    }
}

class Currency
{
    public $iso_code = "EUR";
    public function __construct($id) {}
}
