<?php
define('_DB_PREFIX_', 'ps_');
class Carrier {
    const SHIPPING_METHOD_WEIGHT = 1; const SHIPPING_METHOD_PRICE = 2;
    public $id = 20; public $is_free = false; public $shipping_external = false; public $shipping_handling = false;
    public $method = self::SHIPPING_METHOD_WEIGHT; public $tax = 0;
    public function getShippingMethod() { return $this->method; }
    public function getTaxesRate($address) { return $this->tax; }
    public static function sqlDeliveryRangeShop($range) { return ''; }
}
class Db {
    private static $instance;
    public $rows = [];
    public static function getInstance() { return self::$instance ??= new self(); }
    public function executeS($sql) { return $this->rows; }
}
class Configuration {
    public static $values = ['PS_TAX' => 0, 'PS_ATCP_SHIPWRAP' => 0, 'PS_CURRENCY_DEFAULT' => 1, 'PS_SHIPPING_HANDLING' => 0];
    public static function get($key) { return self::$values[$key]; }
}
class Address { public $id_country; }
class Currency { public static function getCurrencyInstance($id) { return new self(); } }
class Tools {
    public static function convertPrice($price, $currency) { return $price; }
    public static function ps_round($price, $precision) { return round($price, $precision); }
}
class Context {
    public static function getContext() { return new self(); }
    public function getComputingPrecision() { return 2; }
}
