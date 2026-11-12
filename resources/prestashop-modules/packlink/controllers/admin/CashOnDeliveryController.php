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

use Packlink\BusinessLogic\Http\DTO\CashOnDelivery;
use Packlink\PrestaShop\Classes\Bootstrap;
use Packlink\PrestaShop\Classes\BusinessLogicServices\OfflinePaymentService;
use Packlink\BusinessLogic\Controllers\CashOnDeliveryController as CoreController;
use Packlink\PrestaShop\Classes\Utility\PacklinkPrestaShopUtility;


/** @noinspection PhpIncludeInspection */
require_once rtrim(_PS_MODULE_DIR_, '/') . '/packlink/vendor/autoload.php';


class CashOnDeliveryController extends PacklinkBaseController
{
    /**
     * @var OfflinePaymentService
     */
    protected $offlinePaymentService;

    /**
     * @var CoreController $controller
     */
    protected $controller;

    public function __construct()
    {
        parent::__construct();

        Bootstrap::init();

        $this->offlinePaymentService = new OfflinePaymentService();
        $this->controller = new CoreController();
    }

    public function displayAjaxGetData()
    {
        $configuration = $this->getAccountConfiguration();
        $configArray = array();

        if ($configuration !== null) {
            $configArray = $configuration->toArray();
        }

        PacklinkPrestaShopUtility::dieJson(array(
            'paymentMethods' => $this->getOfflinePayments(),
            'configuration' => $configArray,
        ));
    }


    /**
     * @throws \Packlink\BusinessLogic\DTO\Exceptions\FrontDtoValidationException
     */
    public function displayAjaxSaveData()
    {
        $rawData = PacklinkPrestaShopUtility::getPacklinkPostData();

        $this->controller->saveConfig($rawData);
    }

    private function getOfflinePayments()
    {
        return $this->offlinePaymentService->getOfflinePayments();
    }


    /**
     * Retrieves Packlink account configuration and checks if an account exists.
     *
     * @return CashOnDelivery|null
     *
     * @throws \Logeecom\Infrastructure\ORM\Exceptions\QueryFilterInvalidParamException
     */
    private function getAccountConfiguration()
    {
        return $this->controller->getCashOnDeliveryConfiguration();
    }
}
