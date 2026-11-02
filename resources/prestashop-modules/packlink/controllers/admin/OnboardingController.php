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
use Packlink\BusinessLogic\Controllers\OnboardingController as BaseOnboardingController;

/** @noinspection PhpIncludeInspection */
require_once rtrim(_PS_MODULE_DIR_, '/') . '/packlink/vendor/autoload.php';

/**
 * Class OnboardingController
 */
class OnboardingController extends PacklinkBaseController
{
    /**
     * Returns the current state of the on-boarding page.
     */
    public function displayAjaxGetCurrentState()
    {
        $controller = new BaseOnboardingController();

        PacklinkPrestaShopUtility::dieJson($controller->getCurrentState()->toArray());
    }
}
