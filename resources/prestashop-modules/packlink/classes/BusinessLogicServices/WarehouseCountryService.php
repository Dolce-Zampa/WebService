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

use Context;
use Country;
use Packlink\BusinessLogic\Country\WarehouseCountryService as BaseService;

/**
 * Class WarehouseCountryService
 *
 * @package Packlink\PrestaShop\Classes\BusinessLogicServices
 */
class WarehouseCountryService extends BaseService
{
    /**
     * Returns a list of supported country DTOs.
     *
     * @param bool $associative Indicates whether the result should be an associative array.
     *
     * @return \Packlink\BusinessLogic\Country\Country[]
     *
     * @throws \Packlink\BusinessLogic\DTO\Exceptions\FrontDtoNotRegisteredException
     * @throws \Packlink\BusinessLogic\DTO\Exceptions\FrontDtoValidationException
     */
    public function getSupportedCountries($associative = true)
    {
        $countries = $this->getBrandConfigurationService()->get()->warehouseCountries;
        $activeCountries = Country::getCountries((int)Context::getContext()->language->id, true);
        $intersectedCountries = array();

        foreach ($activeCountries as $activeCountry) {
            if (array_key_exists($activeCountry['iso_code'], $countries)) {
                $intersectedCountries[] = $countries[$activeCountry['iso_code']];
            }
        }

        $result = $this->formatCountries($intersectedCountries);

        return $associative ? $result : array_values($result);
    }
}
