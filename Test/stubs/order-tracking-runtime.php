<?php
namespace Packlink\PrestaShop\Classes {
    class Bootstrap { public static function init() {} }
}
namespace Packlink\BusinessLogic\OrderShipmentDetails {
    class OrderShipmentDetailsService { const CLASS_NAME = 'tracking-service'; }
}
namespace Logeecom\Infrastructure {
    class ServiceRegister {
        public static $details;
        public static function getService($name) { return new class {
            public function getDetailsByOrderId($id) { return ServiceRegister::$details; }
        }; }
    }
}
namespace {
    const _PS_VERSION_ = '9.0';
    class PaymentModule {}
    class ModuleFrontController {}
    class Tools {
        public static $values = [];
        public static function getValue($key, $default = false) { return self::$values[$key] ?? $default; }
    }
    class Module {
        public static $enabled = true;
        public static function isEnabled($name) { return self::$enabled; }
        public static function getInstanceByName($name) { return new self(); }
    }
    class Carrier {
        public static $template = '';
        public $url;
        public function __construct($id) { $this->url = self::$template; }
    }
    class Validate { public static function isLoadedObject($object) { return true; } }
    class FakeTrackingOrder {
        public $id = 42;
        public $id_carrier = 9;
        public $number = 'NATIVE';
        public function getShippingNumber() { return $this->number; }
    }
    class Order extends FakeTrackingOrder {
        public function __construct($id) { $this->id = $id; }
    }
    class FakeShipmentDetails {
        public $url = 'https://carrier.example/track/PACK123?lang=it&src=store';
        public $numbers = ['PACK123'];
        public $deleted = false;
        public function isDeleted() { return $this->deleted; }
        public function getCarrierTrackingUrl() { return $this->url; }
        public function getCarrierTrackingNumbers() { return $this->numbers; }
    }
    require __DIR__ . '/../../resources/prestashop-modules/webserviceapi/classes/MlabOrderTracking.php';
    require __DIR__ . '/../../resources/prestashop-modules/webserviceapi/webserviceapi.php';
    class TrackingMailModule extends webserviceapi { public function __construct() {} }
    require __DIR__ . '/../../resources/prestashop-modules/webserviceapi/controllers/front/order.php';
    class TrackingOrderController extends webserviceapiorderModuleFrontController {
        public function tracking($ids) { Tools::$values['tracking_order_ids'] = $ids; return $this->handleGetRequest(); }
    }
}
