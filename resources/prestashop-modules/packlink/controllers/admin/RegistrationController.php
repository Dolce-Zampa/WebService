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
use Packlink\BusinessLogic\Controllers\RegistrationController as BaseRegistrationController;

/** @noinspection PhpIncludeInspection */
require_once rtrim(_PS_MODULE_DIR_, '/') . '/packlink/vendor/autoload.php';

/**
 * Class RegistrationController
 */
class RegistrationController extends PacklinkBaseController
{
    /**
     * Array that identifies e-commerce.
     *
     * @var string[]
     */
    protected static $ecommerceIdentifiers = array('Prestashop');

    /**
     * @var BaseRegistrationController
     */
    private $baseController;

    public function __construct()
    {
        parent::__construct();

        $this->baseController = new BaseRegistrationController();
    }

    /**
     * Returns registration data.
     */
    public function displayAjaxGetRegisterData()
    {
        $country = Tools::getValue('country');

        if (empty($country)) {
            PacklinkPrestaShopUtility::die404(array('message' => 'Not found'));
        }

        PacklinkPrestaShopUtility::dieJson($this->baseController->getRegisterData($country));
    }

    /**
     * Register the user on Packlink.
     */
    public function displayAjaxRegister()
    {
        $payload = PacklinkPrestaShopUtility::getPacklinkPostData();

        $payload['ecommerces'] = static::$ecommerceIdentifiers;

        try {
            $status = $this->baseController->register($payload);
            PacklinkPrestaShopUtility::dieJson(array('success' => $status));
        } catch (Exception $e) {
            PacklinkPrestaShopUtility::dieJson(
                array(
                    'success' => false,
                    'error' => $e->getMessage(),
                )
            );
        }
    }
}
