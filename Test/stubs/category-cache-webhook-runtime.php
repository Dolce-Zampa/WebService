<?php
const _PS_VERSION_ = '9.0';
class PaymentModule { public $context; }
class Db
{
    public static function getInstance(): self { return new self(); }
    public function delete(...$args): bool { return true; }
}
class Product
{
    public static array $categories = [];
    public static array $defaults = [];
    public int $id;
    public int $id_category_default;
    public function __construct($id) { $this->id = $id; $this->id_category_default = self::$defaults[$id] ?? 0; }
    public static function getProductCategories($id) { return self::$categories[$id] ?? []; }
}
require __DIR__ . '/../../resources/prestashop-modules/webserviceapi/webserviceapi.php';
class CategoryCacheWebhookTestModule extends webserviceapi
{
    public array $events = [];
    public function __construct() {}
    protected function clearCacheWebhook(Product $product, ?array $previousCategories = null)
    {
        $this->events[] = [$product->id, $previousCategories, Product::getProductCategories($product->id)];
        return true;
    }
}
