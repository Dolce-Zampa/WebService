<?php
require_once __DIR__ . '/ScheduledPromotionCache.php';

class ScheduledPromotionCacheScan
{
    public static function run()
    {
        $db = Db::getInstance();
        $lockName = substr(_DB_PREFIX_ . 'webserviceapi_promotion_cache', 0, 64);
        $locked = (int) $db->getValue("SELECT GET_LOCK('" . pSQL($lockName) . "', 0)");
        if ($locked !== 1) {
            throw new RuntimeException('Promotion cache scan already running or database lock unavailable.');
        }

        try {
            $module = Module::getInstanceByName('webserviceapi');
            if (!$module || !$module->active
                || !Configuration::get(webserviceapi::CONFIG_PRODUCT_SAVED_WEBHOOK_ENABLED)
                || !Configuration::get(webserviceapi::CONFIG_WEBSERVICE_URL)
                || !Configuration::get(webserviceapi::CONFIG_WEBHOOK_SECRET)) {
                throw new RuntimeException('Module or cache webhook is disabled or not configured.');
            }
            $now = $db->getValue('SELECT NOW()');
            if (!$now) {
                throw new RuntimeException('Unable to read database time.');
            }
            $checkpointKey = 'MLABFACTORYAPI_PROMOTION_CACHE_CHECKPOINT';
            $count = ScheduledPromotionCache::run(
                $now,
                function () use ($checkpointKey) { return Configuration::getGlobalValue($checkpointKey); },
                function ($previous, $now) use ($db) {
                    $lowerFrom = $previous ? " AND `from` >= '" . pSQL($previous) . "'" : '';
                    $lowerTo = $previous ? " AND `to` >= '" . pSQL($previous) . "'" : '';
                    // The end date is inclusive in PrestaShop, so process it only after that second.
                    $rows = $db->executeS('SELECT DISTINCT `id_product` FROM `' . _DB_PREFIX_ . 'specific_price`'
                        . " WHERE `id_product` > 0 AND ((`from` != '0000-00-00 00:00:00' AND `from` <= '" . pSQL($now) . "'" . $lowerFrom . ')'
                        . " OR (`to` != '0000-00-00 00:00:00' AND `to` < '" . pSQL($now) . "'" . $lowerTo . '))');
                    return $rows === false ? false : array_column($rows, 'id_product');
                },
                function ($id) use ($module) { return $module->clearScheduledPromotionCache($id); },
                function ($time) use ($checkpointKey) { return Configuration::updateGlobalValue($checkpointKey, $time); }
            );
            return $count;
        } finally {
            $db->getValue("SELECT RELEASE_LOCK('" . pSQL($lockName) . "')");
        }
    }
}
