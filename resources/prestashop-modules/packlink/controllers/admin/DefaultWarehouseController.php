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

use Packlink\BusinessLogic\Controllers\LocationsController;
use Packlink\BusinessLogic\Controllers\WarehouseController;
use Packlink\PrestaShop\Classes\Utility\PacklinkPrestaShopUtility;
use Packlink\BusinessLogic\Configuration;

/** @noinspection PhpIncludeInspection */
require_once rtrim(_PS_MODULE_DIR_, '/') . '/packlink/vendor/autoload.php';

/**
 * Class DefaultWarehouseController
 */
class DefaultWarehouseController extends PacklinkBaseController
{
    /**
     * Retrieves default warehouse data.
     */
    public function displayAjaxGetDefaultWarehouse()
    {
        $warehouseController = new WarehouseController();

        $warehouse = $warehouseController->getWarehouse();

        PacklinkPrestaShopUtility::dieJson($warehouse ? $warehouse->toArray() : array());
    }

    /**
     * Returns supported Packlink countries.
     *
     * @noinspection PhpParamsInspection
     */
    public function displayAjaxGetSupportedCountries()
    {
        $warehouseController = new WarehouseController();

        Configuration::setUICountryCode($this->context->language->iso_code);
        $countries = $warehouseController->getWarehouseCountries();

        PacklinkPrestaShopUtility::dieDtoEntities($countries);
    }

    /**
     * Saves warehouse data.
     *
     * @throws \Logeecom\Infrastructure\TaskExecution\Exceptions\QueueStorageUnavailableException
     * @throws \Packlink\BusinessLogic\DTO\Exceptions\FrontDtoNotRegisteredException
     * @throws \Logeecom\Infrastructure\TaskExecution\Exceptions\QueueStorageUnavailableException
     */
    public function displayAjaxSubmitDefaultWarehouse()
    {
        $data = PacklinkPrestaShopUtility::getPacklinkPostData();
        $data['default'] = true;
        $warehouseController = new WarehouseController();

        try {
            $warehouse = $warehouseController->updateWarehouse($data);

            PacklinkPrestaShopUtility::dieJson($warehouse->toArray());
        } catch (\Packlink\BusinessLogic\DTO\Exceptions\FrontDtoValidationException $e) {
            PacklinkPrestaShopUtility::die400WithValidationErrors($e->getValidationErrors());
        }
    }

    /**
     * Performs location search.
     */
    public function displayAjaxSearchPostalCodes()
    {
        $input = PacklinkPrestaShopUtility::getPacklinkPostData();

        if (empty($input['query']) || empty($input['country'])) {
            PacklinkPrestaShopUtility::dieJson();
        }

        $locationsController = new LocationsController();

        try {
            PacklinkPrestaShopUtility::dieDtoEntities($locationsController->searchLocations($input));
        } catch (\Exception $e) {
            PacklinkPrestaShopUtility::dieJson();
        }
    }
}
