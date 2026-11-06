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
use Packlink\BusinessLogic\ShipmentDraft\ShipmentDraftService;
use Packlink\PrestaShop\Classes\Utility\PacklinkPrestaShopUtility;
use Logeecom\Infrastructure\TaskExecution\QueueItem;
use Packlink\BusinessLogic\OrderShipmentDetails\OrderShipmentDetailsService;

/** @noinspection PhpIncludeInspection */
require_once rtrim(_PS_MODULE_DIR_, '/') . '/packlink/vendor/autoload.php';

/**
 * Class OrderDraftController
 */
class OrderDraftController extends PacklinkBaseController
{
    /**
     * @var ShipmentDraftService
     */
    private $shipmentDraftService;
    /**
     * @var OrderShipmentDetailsService
     */
    private $orderShipmentDetailsService;

    /**
     * Creates order draft for order identified by ID in the request by enqueuing SendDraftTask.
     */
    public function displayAjaxCreateOrderDraft()
    {
        $data = PacklinkPrestaShopUtility::getPacklinkPostData();

        if ($data['orderId']) {
            try {
                $this->getShipmentDraftService()->enqueueCreateShipmentDraftTask((string)$data['orderId']);
            } catch (\Exception $e) {
                PacklinkPrestaShopUtility::die500(array(
                    'success' => false,
                    'message' => $e->getMessage(),
                ));
            }

            PacklinkPrestaShopUtility::dieJson(array('success' => true));
        }

        PacklinkPrestaShopUtility::die400(array('message' => 'Order ID missing'));
    }

    /**
     * Returns the current status of shipment draft for the provided order ID.
     */
    public function displayAjaxGetDraftStatus()
    {
        $orderId = Tools::getValue('orderId');

        if (!$orderId) {
            PacklinkPrestaShopUtility::die400(array('message' => 'Order ID missing'));
        }

        $draftStatus = $this->getShipmentDraftService()->getDraftStatus($orderId);

        if ($draftStatus->status === QueueItem::COMPLETED) {
            $shipmentDetails = $this->getOrderShipmentDetailsService()->getDetailsByOrderId($orderId);

            if ($shipmentDetails === null) {
                PacklinkPrestaShopUtility::die404(array('message' => 'Order shipment details not found'));
            }

            PacklinkPrestaShopUtility::dieJson(array(
                'status' => 'created',
                'shipmentUrl' => $shipmentDetails->getShipmentUrl(),
            ));
        }

        PacklinkPrestaShopUtility::dieJson(array(
            'status' => $draftStatus->status,
            'shipmentUrl' => '',
        ));
    }

    /**
     * Returns an instance of order shipment details service.
     *
     * @return \Packlink\BusinessLogic\OrderShipmentDetails\OrderShipmentDetailsService
     */
    private function getOrderShipmentDetailsService()
    {
        if ($this->orderShipmentDetailsService === null) {
            $this->orderShipmentDetailsService = ServiceRegister::getService(OrderShipmentDetailsService::CLASS_NAME);
        }

        return $this->orderShipmentDetailsService;
    }

    /**
     * Returns an instance of shipment draft service.
     *
     * @return \Packlink\BusinessLogic\ShipmentDraft\ShipmentDraftService
     */
    private function getShipmentDraftService()
    {
        if ($this->shipmentDraftService === null) {
            $this->shipmentDraftService = ServiceRegister::getService(ShipmentDraftService::CLASS_NAME);
        }

        return $this->shipmentDraftService;
    }
}
