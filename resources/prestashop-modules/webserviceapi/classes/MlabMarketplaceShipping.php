<?php

/** Shipping policy shared by the PrestaShop quote and order calculation. */
final class MlabMarketplaceShipping
{
    public static function ensureReady($module, $context)
    {
        $shopId = (int) ($context->shop->id ?? 0);
        if (!defined('_PS_VERSION_') || version_compare(_PS_VERSION_, '9.2.0', '<')
            || !$module || $shopId <= 0 || !class_exists('Hook') || !method_exists('Hook', 'isModuleRegisteredOnHook')) {
            throw new MlabFactoryApiException('Marketplace shipping is not configured.', 503);
        }
        foreach (array('actionCartGetPackageShippingCost', 'actionOverrideShippingFreePrice', 'actionOverrideShippingFreeWeight') as $hook) {
            if (!Hook::isModuleRegisteredOnHook($module, $hook, $shopId)) {
                throw new MlabFactoryApiException('Marketplace shipping is not configured.', 503);
            }
        }
    }

    public static function threshold()
    {
        $value = getenv('MARKETPLACE_FREE_SHIPPING_THRESHOLD');
        if ($value === false || trim((string) $value) === '') {
            return 99.0;
        }
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
            throw new InvalidArgumentException('Invalid marketplace free shipping threshold.');
        }
        return (float) $value;
    }

    /** Group physical products by their authoritative PrestaShop manufacturer. */
    public static function groupProducts(array $products)
    {
        $groups = array();
        foreach ($products as $product) {
            if (!empty($product['is_virtual'])) {
                continue;
            }
            $id = (int) ($product['id_manufacturer'] ?? 0);
            if ($id <= 0) {
                throw new InvalidArgumentException('A physical product has no artisan.');
            }
            $groups[$id][] = $product;
        }
        return $groups;
    }

    /** Obtain each artisan's shipping from the carrier's own price calculation. */
    public static function calculate(array $products, $threshold, callable $quote)
    {
        $total = 0.0;
        foreach (self::groupProducts($products) as $id => $lines) {
            $subtotal = 0.0;
            foreach ($lines as $line) {
                if (!isset($line['total_wt']) || !is_numeric($line['total_wt']) || (float) $line['total_wt'] < 0) {
                    throw new InvalidArgumentException('Invalid artisan subtotal.');
                }
                $subtotal += (float) $line['total_wt'];
            }
            if ($threshold > 0 && round($subtotal, 2) >= $threshold) {
                continue;
            }
            $price = $quote($id, $lines);
            if ($price === false) {
                return false;
            }
            if (!is_numeric($price) || !is_finite((float) $price) || (float) $price < 0) {
                throw new InvalidArgumentException('Invalid carrier price.');
            }
            $total += (float) $price;
        }
        return round($total, 2);
    }
}
