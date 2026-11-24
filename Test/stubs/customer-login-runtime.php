<?php
namespace PrestaShop\PrestaShop\Adapter {
    class ServiceLocator {
        public static function get($service) { throw new \Exception('Unavailable in test'); }
    }
}
namespace {
    const _DB_PREFIX_ = 'fy8ie_';
    const _COOKIE_KEY_ = 'test';
    function pSQL($value) { return addslashes($value); }
    class ModuleFrontController {}
    class Validate {
        public static function isEmail($email) { return (bool) filter_var($email, FILTER_VALIDATE_EMAIL); }
        public static function isLoadedObject($object) { return $object && !empty($object->id); }
    }
    class Customer {
        public $id, $passwd, $active = true, $id_default_group = 3, $id_gender = 0;
        public $firstname = 'Test', $lastname = 'Customer', $email = 'test@example.test';
        public $birthday = '', $newsletter = 0, $optin = 0, $secure_key = '';
        public function __construct($id) { $this->id = $id; $this->passwd = password_hash('correct', PASSWORD_BCRYPT); $this->active = Db::$active; }
        public function getAddresses($languageId) { return []; }
    }
    class Db {
        public static $active = true;
        public static $updates = [];
        public static $updateResult = true;
        public static function getInstance() { return new self(); }
        public function getValue($sql) { return 7; }
        public function update($table, $data, $where) { self::$updates[] = [$table, $data, $where]; return self::$updateResult; }
    }
    require __DIR__ . '/../../resources/prestashop-modules/webserviceapi/controllers/front/login.php';
    class TestLoginController extends \webserviceapiloginModuleFrontController {
        public $payload;
        public $context;
        public function __construct($password) {
            $this->payload = ['email' => 'test@example.test', 'password' => $password];
            $this->context = (object) ['language' => (object) ['id' => 1]];
        }
        protected function getJsonPayload() { return $this->payload; }
        protected function getCustomerContext(\Customer $customer) {}
        public function run() { return $this->handleRequest(); }
    }
}
