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

use Packlink\BusinessLogic\Controllers\OrderStatusMappingController;
use Packlink\BusinessLogic\Language\Translator;
use Packlink\PrestaShop\Classes\Utility\PacklinkPrestaShopUtility;
use Packlink\BusinessLogic\Configuration;

/** @noinspection PhpIncludeInspection */
require_once rtrim(_PS_MODULE_DIR_, '/') . '/packlink/vendor/autoload.php';

class OrderStateMappingController extends PacklinkBaseController
{
    /** @var OrderStatusMappingController */
    private $baseController;

    public function __construct()
    {
        parent::__construct();

        $this->baseController = new OrderStatusMappingController();
    }

    /**
     * Retrieves order status mappings.
     */
    public function displayAjaxGetMappingsAndStatuses()
    {
        Configuration::setUICountryCode($this->context->language->iso_code);

        PacklinkPrestaShopUtility::dieJson(array(
            'systemName' => $this->getConfigService()->getIntegrationName(),
            'mappings' => $this->baseController->getMappings(),
            'packlinkStatuses' => $this->baseController->getPacklinkStatuses(),
            'orderStatuses' => $this->getSystemOrderStatuses(),
        ));
    }

    /**
     * Saves order status mappings.
     */
    public function displayAjaxSaveMappings()
    {
        $data = PacklinkPrestaShopUtility::getPacklinkPostData();
        $this->baseController->setMappings($data);

        PacklinkPrestaShopUtility::dieJson(array('success' => true));
    }

    /**
     * Retrieves all order statuses that are present in Prestashop.
     */
    private function getSystemOrderStatuses()
    {
        $result = array('' => Translator::translate('orderStatusMapping.none'));

        $states = OrderStateCore::getOrderStates($this->context->language->id);

        foreach ($states as $state) {
            $result[$state['id_order_state']] =  $state['name'];
        }

        return $result;
    }
}
