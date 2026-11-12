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

/** @noinspection PhpUndefinedClassInspection */
/** @noinspection PhpUnusedPrivateMethodInspection */
/** @noinspection PhpIncludeInspection */

/**
 * Class AdminOrdersController
 */
class AdminOrdersController extends AdminOrdersControllerCore
{
    /**
     * @var \Packlink\PrestaShop\Classes\Overrides\AdminOrdersController
     */
    private $packlinkAdminOrderController;
    /**
     * AdminOrdersController constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->initializePacklinkHandler();
    }

    /**
     * Renders invoice and shipment label icons.
     *
     * @param string $orderId ID of the order.
     * @param array $tr Table row.
     *
     * @return string Rendered template output.
     *
     * @throws \Logeecom\Infrastructure\ORM\Exceptions\QueryFilterInvalidParamException
     * @throws \PrestaShopDatabaseException
     * @throws \PrestaShopException
     * @throws \SmartyException
     */
    public function printPDFIcons($orderId, $tr)
    {
        return $this->packlinkAdminOrderController->renderPdfIcons($orderId, $this->context);
    }

    /**
     * Returns template that should be rendered in order draft column within orders table.
     *
     * @param string $orderId ID of the order.
     *
     * @return string Rendered template output.
     *
     * @throws \Logeecom\Infrastructure\ORM\Exceptions\QueryFilterInvalidParamException
     * @throws \SmartyException
     * @throws \PrestaShopException
     */
    public function getOrderDraft($orderId)
    {
        return $this->packlinkAdminOrderController->getOrderDraft($orderId, $this->context);
    }

    /**
     * Initializes Packlink module handler for extending order details page.
     */
    private function initializePacklinkHandler()
    {
        require_once rtrim(_PS_MODULE_DIR_, '/') . '/packlink/vendor/autoload.php';

        $this->packlinkAdminOrderController = new \Packlink\PrestaShop\Classes\Overrides\AdminOrdersController();

        $this->fields_list = $this->packlinkAdminOrderController->insertOrderColumn($this->fields_list);

        $this->packlinkAdminOrderController->addBulkActions($this->bulk_actions);
    }
}
