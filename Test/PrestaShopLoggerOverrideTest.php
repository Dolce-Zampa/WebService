<?php
/**
 * Smoke test per l'override PrestaShopLogger (resources/overrides/classes/PrestaShopLogger.php).
 *
 * Esegue l'override contro stub minimi di PrestaShopLoggerCore / Configuration / Context / Module
 * e contro una fake classe Logtail per verificare che i log vengano inoltrati a "BetterStack"
 * con il mapping di severità corretto.
 *
 * Uso: php Test/PrestaShopLoggerOverrideTest.php
 */

namespace {
    define('_PS_VERSION_', '8.1.0.0');
    define('_PS_MODE_DEV_', false);
}

// ---- Stub Monolog ---------------------------------------------------------
namespace Monolog {
    enum Level: int
    {
        case Debug = 200;
        case Info = 250;
        case Notice = 300;
        case Warning = 400;
        case Error = 500;
        case Critical = 550;
        case Alert = 600;
        case Emergency = 650;
    }

    class Logger
    {
        public string $name;
        public array $records = [];
        private array $handlers = [];

        public function __construct(string $name)
        {
            $this->name = $name;
        }

        public function pushHandler($handler): void
        {
            $this->handlers[] = $handler;
        }

        public function log($level, string $message, array $context = []): void
        {
            $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
        }
    }
}

namespace Monolog\Formatter {
    class LineFormatter
    {
        public function __construct($format = null, $date = null, $allowInlineLineBreaks = false, $ignoreEmptyContextAndExtra = false)
        {
        }
        public function includeStacktraces(bool $b = true): void
        {
        }
        public function setJsonPrettyPrint(bool $b): void
        {
        }
    }
}

namespace Logtail\Monolog {
    class FakeLogtailHandler
    {
        public $sourceToken;
        public $endpoint;
        public $level;
        public $formatter;
        public function setFormatter($f)
        {
            $this->formatter = $f;
        }
    }

    class LogtailHandlerBuilder
    {
        private static $lastBuilt;
        private $token;
        private $endpoint;
        private $level;

        public static function withSourceToken($token)
        {
            $b = new self();
            $b->token = $token;
            return $b;
        }
        public function withEndpoint($e)
        {
            $this->endpoint = $e;
            return $this;
        }
        public function withLevel($l)
        {
            $this->level = $l;
            return $this;
        }
        public function build()
        {
            $h = new FakeLogtailHandler();
            $h->sourceToken = $this->token;
            $h->endpoint = $this->endpoint;
            $h->level = $this->level;
            self::$lastBuilt = $h;
            return $h;
        }
        public static function lastBuilt()
        {
            return self::$lastBuilt;
        }
    }
}

// ---- Stub PrestaShop ------------------------------------------------------
namespace {
    class PrestaShopLoggerCore
    {
        const SEVERITY_LOG = 0;
        const SEVERITY_WARNING = 1;
        const SEVERITY_ERROR = 2;
        const SEVERITY_CRITICAL = 3;
        const SEVERITY_PARSE = 4;
        public static $dbLogs = [];

        public static function addLog(
            $message,
            $severity = 0,
            $error_code = null,
            $object_type = null,
            $object_id = null,
            $employee = null,
            $date_add = null
        ) {
            self::$dbLogs[] = [$message, $severity];
            return count(self::$dbLogs);
        }
    }

    class ShopStub
    {
        public $id = 1;
        public $name = 'DolceZampa';
    }

    class Context
    {
        public $shop;
        public function __construct()
        {
            $this->shop = new ShopStub();
        }
        public static function getContext()
        {
            return new self();
        }
    }

    class Configuration
    {
        public static $values = [];
        public static function get($name, $idLang = null, $idShopGroup = null, $idShop = null, $default = null)
        {
            return self::$values[$name] ?? $default;
        }
    }

    class Module
    {
        public $active = false;
        public static function getInstanceByName($name)
        {
            if ($name === 'logfordev') {
                $m = new self();
                $m->active = !empty($GLOBALS['LOGFORDEV_ACTIVE']);
                return $m;
            }
            return false;
        }
    }

    // Carica l'override sotto test.
    require __DIR__ . '/../resources/overrides/classes/PrestaShopLogger.php';

    // ---- Assertions --------------------------------------------------------
    $failures = 0;
    function check($label, $condition)
    {
        global $failures;
        if ($condition) {
            echo "OK   - $label\n";
        } else {
            echo "FAIL - $label\n";
            $failures++;
        }
    }

    putenv('APP_ENV=production');
    putenv('LOGTAIL_API_KEY=test-token-123');
    unset($_SERVER['LOGTAIL_API_KEY'], $_SERVER['LOGTAIL_ENDPOINT']);

    // 1) Forwarding attivo in produzione con API key
    PrestaShopLogger::addLog('Ordine #10 creato', 0, '', 'order', 10);
    PrestaShopLogger::addLog('Attention needed', 1);
    PrestaShopLogger::addLog('Boom!', 2, 'E42', 'product', 7);

    check('log salvato anche su ps_log (parent)', count(PrestaShopLoggerCore::$dbLogs) === 3);

    $ref = new ReflectionClass('PrestaShopLogger');
    $loggerProp = $ref->getProperty('betterStackLogger');
    $loggerProp->setAccessible(true);
    $logger = $loggerProp->getValue();
    check('Monolog logger inizializzato', $logger instanceof \Monolog\Logger);

    $records = $logger ? $logger->records : [];
    check('3 record inoltrati a BetterStack', count($records) === 3);
    check('severity 0 -> Notice', isset($records[0]) && $records[0]['level'] === \Monolog\Level::Notice);
    check('severity 1 -> Warning', isset($records[1]) && $records[1]['level'] === \Monolog\Level::Warning);
    check('severity 2 -> Error', isset($records[2]) && $records[2]['level'] === \Monolog\Level::Error);
    check('context contiene object_type/object_id/error_code',
        isset($records[2])
        && $records[2]['context']['object_type'] === 'product'
        && $records[2]['context']['object_id'] === 7
        && $records[2]['context']['error_code'] === 'E42');
    check('context contiene shop_id', ($records[0]['context']['shop_id'] ?? null) === 1);

    $built = \Logtail\Monolog\LogtailHandlerBuilder::lastBuilt();
    check('handler costruito con source token', $built && $built->sourceToken === 'test-token-123');
    check('handler con endpoint default', $built && $built->endpoint === 'https://in.logtail.com');

    // 2) Senza API key => nessun forwarding
    putenv('LOGTAIL_API_KEY');
    $before = count($records);
    PrestaShopLogger::addLog('should not forward', 2);
    $logger2 = $loggerProp->getValue();
    check('senza LOGTAIL_API_KEY non viene inoltrato', count($logger2->records) === $before);
    check('ma il log su ps_log avviene comunque', count(PrestaShopLoggerCore::$dbLogs) === 4);

    // 3) PS_LOG_BETTERSTACK_ENABLED forza on anche fuori produzione
    putenv('LOGTAIL_API_KEY=test-token-123');
    putenv('APP_ENV=development');
    Configuration::$values['PS_LOG_BETTERSTACK_ENABLED'] = '1';
    PrestaShopLogger::addLog('forced on', 3);
    $logger3 = $loggerProp->getValue();
    check('enabled via Configuration anche in development', count($logger3->records) === $before + 1);
    check('severity 3 -> Critical', end($logger3->records)['level'] === \Monolog\Level::Critical);

    // 4) PS_LOG_DISABLE_BETTERSTACK disattiva tutto
    Configuration::$values['PS_LOG_BETTERSTACK_ENABLED'] = '0';
    PrestaShopLogger::addLog('forced off', 2);
    check('disabled via Configuration', count($loggerProp->getValue()->records) === $before + 1);

    echo $failures === 0 ? "\nALL TESTS PASSED\n" : "\n$failures FAILURE(S)\n";
    exit($failures === 0 ? 0 : 1);
}
