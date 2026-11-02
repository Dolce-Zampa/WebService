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

use Packlink\BusinessLogic\Registration\RegistrationInfoService as RegistrationInfoServiceInterface;
use Packlink\BusinessLogic\Registration\RegistrationInfo;

/**
 * Class RegistrationInfoService
 *
 * @package Packlink\PrestaShop\Classes\BusinessLogicServices
 */
class RegistrationInfoService implements RegistrationInfoServiceInterface
{
    /**
     * Returns registration data from the integration.
     *
     * @return RegistrationInfo
     */
    public function getRegistrationInfoData()
    {
        $data = $this->getRegistrationData();

        return new RegistrationInfo($data['email'], $data['phone'], $data['source']);
    }

    /**
     * Returns registration data from PrestaShop.
     *
     * @return array
     */
    private function getRegistrationData()
    {
        $result = array();

        $result['email'] = \Context::getContext()->employee->email;
        $result['phone']  =  '';
        $result['source'] = \ShopUrlCore::getMainShopDomain() . \Context::getContext()->shop->physical_uri;

        return $result;
    }
}
