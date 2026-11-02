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
use Packlink\BusinessLogic\Controllers\LoginController as BaseLoginController;

/** @noinspection PhpIncludeInspection */
require_once rtrim(_PS_MODULE_DIR_, '/') . '/packlink/vendor/autoload.php';

/**
 * Class LoginController
 */
class LoginController extends PacklinkBaseController
{
    /**
     * Attempts to log the user in with the provided Packlink API key.
     *
     * @throws \Logeecom\Infrastructure\ORM\Exceptions\RepositoryNotRegisteredException
     * @throws \Logeecom\Infrastructure\TaskExecution\Exceptions\QueueStorageUnavailableException
     */
    public function displayAjaxLogin()
    {
        $data = PacklinkPrestaShopUtility::getPacklinkPostData();
        $controller = new BaseLoginController();
        $status = $controller->login(!empty($data['apiKey']) ? $data['apiKey'] : '');

        PacklinkPrestaShopUtility::dieJson(array('success' => $status));
    }
}
