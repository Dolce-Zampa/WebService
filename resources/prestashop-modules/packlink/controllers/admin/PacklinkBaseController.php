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

use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\Configuration;
use Packlink\PrestaShop\Classes\Bootstrap;
use Packlink\PrestaShop\Classes\BusinessLogicServices\ConfigurationService;

/**
 * Class PacklinkBaseController.
 */
class PacklinkBaseController extends ModuleAdminController
{
    /**
     * @var ConfigurationService
     */
    private $configService;

    /**
     * DebugController constructor.
     *
     * @throws \PrestaShopException
     */
    public function __construct()
    {
        parent::__construct();

        Bootstrap::init();

        $this->bootstrap = true;
    }

    /**
     * Retrieves configuration service.
     *
     * @return ConfigurationService Configuration service instance.
     */
    protected function getConfigService()
    {
        if ($this->configService === null) {
            $this->configService = ServiceRegister::getService(Configuration::CLASS_NAME);
        }

        return $this->configService;
    }

    /**
     * Retrieves ajax action.
     *
     * @param string $controller
     * @param string $action
     * @param bool $ajax
     *
     * @return string
     *
     * @throws \PrestaShopException
     */
    protected function getAction($controller, $action, $ajax = true)
    {
        return $this->context->link->getAdminLink($controller) . '&' .
            http_build_query(
                array(
                    'ajax' => $ajax,
                    'action' => $action,
                )
            );
    }

    /**
     * @noinspection SenselessProxyMethodInspection
     *
     * Added to suppress warning.
     *
     * @inheritDoc
     */
    protected function l($string, $class = null, $addSlashes = false, $htmlEntities = true)
    {
        if (defined('_PS_VERSION_') &&
            version_compare(_PS_VERSION_, '1.7.0.0', '<')) {
            return parent::l($string, $class, $addSlashes, $htmlEntities);
        }

        return $this->trans($string);
    }
}
