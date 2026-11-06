<?php
$fixtureDirectory = sys_get_temp_dir() . '/packlink-hook-test-' . getmypid() . '/';
mkdir($fixtureDirectory . 'packlink/vendor', 0777, true);
file_put_contents($fixtureDirectory . 'packlink/vendor/autoload.php', <<<'AUTOLOAD'
<?php
namespace Packlink\PrestaShop\Classes;
class Bootstrap { public static function init() { throw new \RuntimeException('Provider unavailable'); } }
AUTOLOAD);
define('_PS_MODULE_DIR_', $fixtureDirectory);
define('_PS_VERSION_', '9.2.0');
class CarrierModule {}
class PrestaShopLogger { public static $messages = []; public static function addLog($message, $severity) { self::$messages[] = $message; } }
class Configuration { public static function get($key) { return '2026-11-05 12:00:00'; } }
class Order { public $id = 123; public $date_add = '2026-11-05 12:00:01'; }
class OrderState {}
require __DIR__ . '/../../resources/prestashop-modules/packlink/packlink.php';
