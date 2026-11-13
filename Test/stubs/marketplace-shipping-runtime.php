<?php
define('_PS_VERSION_', '9.2.0');
class PaymentModule {}
class Cache { public static function clean($key) {} }

final class MarketplaceShippingCartStub
{
    public $id = 42;
    public $quotedCarriers = [];
    public $disabledGlobalThresholds = 0;
    public function __construct(private $module) {}
    public function getProducts()
    {
        return [
            ['id_manufacturer' => 1, 'total_wt' => 30, 'is_virtual' => 0],
            ['id_manufacturer' => 2, 'total_wt' => 90, 'is_virtual' => 0],
            ['id_manufacturer' => 3, 'total_wt' => 100, 'is_virtual' => 0],
        ];
    }
    public function getPackageShippingCost($carrier, $tax, $country, $products, $zone, $keepOrderPrices)
    {
        $price = 99.0;
        $this->module->hookActionOverrideShippingFreePrice(['shippingFreePrice' => &$price]);
        if ($price === 0) ++$this->disabledGlobalThresholds;
        $this->quotedCarriers[] = $carrier;
        return $carrier === 20 ? 3.90 : 6.10;
    }
}
