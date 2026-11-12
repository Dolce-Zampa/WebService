<?php
/**
 * 2025 Packlink
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Apache License 2.0
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at this URL:
 * http://www.apache.org/licenses/LICENSE-2.0.txt
 *
 * @author    Packlink <support@packlink.com>
 * @copyright 2025 Packlink Shipping S.L
 * @license   http://www.apache.org/licenses/LICENSE-2.0.txt  Apache License 2.0
 */
namespace Packlink\PrestaShop\Classes\BusinessLogicServices;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Packlink\BusinessLogic\Http\DTO\SystemInfo;
use Packlink\BusinessLogic\SystemInformation\SystemInfoService as SystemInfoInterface;

/**
 * Class SystemInfoService
 *
 * @package Packlink\PrestaShop\Classes\BusinessLogicServices
 */
class SystemInfoService implements SystemInfoInterface
{
    /**
     * Returns system information.
     *
     * @return SystemInfo[]
     *
     * @throws \PrestaShopDatabaseException
     */
    public function getSystemDetails()
    {
        $shopIds = \Shop::getCompleteListOfShopsID();
        $systemInfos = array();

        foreach ($shopIds as $shopId) {
            $systemInfos[] = $this->getSystemInfo($shopId);
        }

        return array_filter($systemInfos);
    }

    /**
     * Returns system information for a particular system, identified by the system ID.
     *
     * @param string $systemId
     *
     * @return SystemInfo|null
     *
     * @throws \PrestaShopDatabaseException
     */
    public function getSystemInfo($systemId)
    {
        $shop = \Shop::getShop($systemId);

        if ($shop) {
            return SystemInfo::fromArray(array(
                'system_id' => $systemId,
                'system_name' => $shop['name'],
                'currencies' => $this->getCurrencies($systemId),
            ));
        }

        return null;
    }

    /**
     * Returns a list of supported shop currencies.
     *
     * @param string $systemId
     *
     * @return array
     */
    private function getCurrencies($systemId = null)
    {
        if ($systemId === null) {
            return array();
        }

        $currency = new \Currency(\Configuration::get(
            'PS_CURRENCY_DEFAULT',
            null,
            null,
            $systemId
        ));

        return array($currency->iso_code);
    }
}
