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
if (!defined('_PS_VERSION_')) {
    exit;
}

use Packlink\PrestaShop\Classes\Utility\PacklinkPrestaShopUtility;

/** @noinspection PhpIncludeInspection */
require_once rtrim(_PS_MODULE_DIR_, '/') . '/packlink/vendor/autoload.php';

/**
 * Class ShippingZonesController
 */
class ShippingZonesController extends PacklinkBaseController
{
    /**
     * Returns available shipping zones.
     */
    public function displayAjaxGetShippingZones()
    {
        $zones = Zone::getZones(true);

        $result = array_map(
            static function ($zone) {
                return array(
                    'value' => (string)$zone['id_zone'],
                    'label' => $zone['name'],
                );
            },
            $zones
        );

        $result = array_values($result);

        PacklinkPrestaShopUtility::dieJson($result);
    }
}
