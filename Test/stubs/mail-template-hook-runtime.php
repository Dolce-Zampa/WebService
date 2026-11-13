<?php
const _PS_VERSION_ = '9.0';
class PaymentModule {}
class Order
{
    public $id = 42;
    public $id_carrier = 0;
    public $id_lang = 1;
    public $id_shop = 1;
    public function __construct($id) {}
    public function getShippingNumber() { return 'BRT123456'; }
    public function getProducts() { return [['product_id' => 7, 'product_name' => 'Collare']]; }
}
class OrderCarrier {}
class Product
{
    public $id_manufacturer = 0;
    public $available_now = '3 giorni';
    public function __construct($id, $full, $lang, $shop) {}
}
class Validate
{
    public static function isLoadedObject($object) { return true; }
}
require __DIR__ . '/../../resources/prestashop-modules/webserviceapi/webserviceapi.php';
class MailTemplateHookTestModule extends webserviceapi
{
    public function __construct() {}
}
