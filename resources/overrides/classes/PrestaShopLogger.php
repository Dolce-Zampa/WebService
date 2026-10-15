<?php
/**
 * Override di PrestaShopLogger per il progetto DolceZampa (webservice).
 *
 * Installazione (copiare nella root del negozio PrestaShop):
 *   cp resources/overrides/classes/PrestaShopLogger.php <prestashop>/override/classes/PrestaShopLogger.php
 *   rm -f <prestashop>/var/cache/prod/class_index.php   (oppure cancellare l'intera cartella var/cache)
 *
 * Da questo momento ogni chiamata a PrestaShopLogger::addLog() viene:
 *   1. salvata nel database, esattamente come nel core di PrestaShop;
 *   2. inoltrata a BetterStack (Logtail) tramite Monolog, con la stessa
 *      configurazione usata in config/logger.php del microservizio
 *      (LOG_CHANNEL=logtail + LOGTAIL_API_KEY + LOGTAIL_ENDPOINT).
 *
 * L'invio verso BetterStack è disattivabile senza toccare il codice tramite i
 * Configuration values PS_LOG_BETTERSTACK_ENABLED / PS_LOG_BETTERSTACK_LEVEL.
 *
 * @author MlabFactory - Marco De Felice
 */
class PrestaShopLogger extends PrestaShopLoggerCore
{
    /**
     * Mappa le severità PrestaShop (0..4) sui livelli Monolog.
     */
    private const BETTERSTACK_DEFAULT_LEVELS = [
        0 => \Monolog\Level::Notice,   // log (informazione)
        1 => \Monolog\Level::Warning,  // warning
        2 => \Monolog\Level::Error,    // error
        3 => \Monolog\Level::Critical, // severe problem
        4 => \Monolog\Level::Info,     // event (debug)
    ];

    /** @var \Monolog\Logger|null logger initialized lazily */
    private static $betterStackLogger = null;

    /** @var bool whether the BetterStack handler failed to initialize */
    private static $betterStackInitFailed = false;

    /**
     * {@inheritdoc}
     *
     * Dopo lo standard salvataggio in tabella ps_log, inoltra il log su BetterStack.
     */
    public static function addLog(
        $message,
        $severity = self::SEVERITY_LOG,
        $error_code = null,
        $object_type = null,
        $object_id = null,
        $employee = null,
        $date_add = null
    ) {
        // Comportamento originale: scrittura su ps_log (già robusto ai DB down).
        $result = parent::addLog(
            $message,
            $severity,
            $error_code,
            $object_type,
            $object_id,
            $employee,
            $date_add
        );

        try {
            self::sendToBetterStack($message, $severity, $error_code, $object_type, $object_id);
        } catch (\Throwable $e) {
            // Non devono mai rompere il flusso di PrestaShop: al massimo si finisce su stderr.
            error_log('[PrestaShopLogger override] BetterStack forwarding failed: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Ritorna true se l'invio verso BetterStack è abilitato.
     *
     * Gate, in ordine:
     *  - env PS_LOG_DISABLE_BETTERSTACK=1 forza off;
     *  - LOGTAIL_API_KEY deve essere presente;
     *  - modulo logfordev attivo => lasciamo fare a lui (evitiamo doppi invii);
     *  - Configuration PS_LOG_BETTERSTACK_ENABLED (default: on solo in produzione,
     *    come config/logger.php che usa Logtail solo con APP_ENV=production).
     */
    protected static function isBetterStackEnabled()
    {
        if (filter_var(getenv('PS_LOG_DISABLE_BETTERSTACK') ?: '0', FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $apiKey = $_SERVER['LOGTAIL_API_KEY'] ?? (getenv('LOGTAIL_API_KEY') ?: '');
        if (empty($apiKey)) {
            return false;
        }

        // Se logfordev è installato e attivo gestisce lui i log: niente doppio invio.
        if (class_exists('Module', false)) {
            try {
                $logForDev = Module::getInstanceByName('logfordev');
                if ($logForDev && $logForDev->active) {
                    return false;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        try {
            $enabled = Configuration::get('PS_LOG_BETTERSTACK_ENABLED');
            if ($enabled === false || $enabled === null || $enabled === '') {
                // Default: on solo in produzione, come config/logger.php.
                $appEnv = getenv('APP_ENV') ?: (_PS_MODE_DEV_ ? 'development' : 'production');
                return $appEnv === 'production';
            }
            return filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Costruisce (una sola volta) il Monolog logger con handler Logtail/BetterStack.
     * Replica la logica di config/logger.php.
     *
     * @return \Monolog\Logger|null
     */
    protected static function getBetterStackLogger()
    {
        if (self::$betterStackLogger !== null || self::$betterStackInitFailed) {
            return self::$betterStackLogger;
        }

        try {
            if (!class_exists(\Monolog\Logger::class)) {
                throw new \RuntimeException('Monolog not available inside PrestaShop context.');
            }
            if (!class_exists(\Logtail\Monolog\LogtailHandlerBuilder::class)) {
                throw new \RuntimeException(
                    'Logtail handler not available: composer require logtail/monolog-logtail in the PrestaShop root.'
                );
            }

            $apiKey = $_SERVER['LOGTAIL_API_KEY'] ?? (getenv('LOGTAIL_API_KEY') ?: '');
            $endpoint = $_SERVER['LOGTAIL_ENDPOINT'] ?? getenv('LOGTAIL_ENDPOINT');
            if (empty($endpoint)) {
                $endpoint = 'https://in.logtail.com';
            }

            $loggerName = getenv('APP_NAME') ?: 'DolceZampa-PrestaShop';
            if (trim($loggerName) === '') {
                $loggerName = 'DolceZampa-PrestaShop';
            }

            $level = self::resolveMinimumLevel();

            $handler = \Logtail\Monolog\LogtailHandlerBuilder::withSourceToken($apiKey)
                ->withEndpoint($endpoint)
                ->withLevel($level)
                ->build();

            $formatter = new \Monolog\Formatter\LineFormatter(
                "[%channel%][%datetime%] %level_name%: %message% %context% %extra%\n",
                'Y-m-d H:i:s.v',
                true,
                true
            );
            $formatter->includeStacktraces(true);
            $formatter->setJsonPrettyPrint(true);
            $handler->setFormatter($formatter);

            $logger = new \Monolog\Logger($loggerName);
            $logger->pushHandler($handler);

            self::$betterStackLogger = $logger;
        } catch (\Throwable $e) {
            self::$betterStackInitFailed = true;
            self::$betterStackLogger = null;
            error_log('[PrestaShopLogger override] BetterStack init failed: ' . $e->getMessage());
        }

        return self::$betterStackLogger;
    }

    /**
     * Livello minimo: Configuration PS_LOG_BETTERSTACK_LEVEL, poi APP_LOG_LEVEL, default debug.
     *
     * @return \Monolog\Level
     */
    protected static function resolveMinimumLevel()
    {
        $name = '';
        try {
            $name = (string) Configuration::get('PS_LOG_BETTERSTACK_LEVEL');
        } catch (\Throwable $e) {
            // ignore
        }
        if ($name === '') {
            $name = getenv('APP_LOG_LEVEL') ?: 'debug';
        }

        $map = [
            'debug' => \Monolog\Level::Debug,
            'info' => \Monolog\Level::Info,
            'notice' => \Monolog\Level::Notice,
            'warning' => \Monolog\Level::Warning,
            'error' => \Monolog\Level::Error,
            'critical' => \Monolog\Level::Critical,
            'alert' => \Monolog\Level::Alert,
            'emergency' => \Monolog\Level::Emergency,
        ];

        return $map[strtolower($name)] ?? \Monolog\Level::Debug;
    }

    /**
     * Mappa le severità PrestaShop (0..4) sui livelli Monolog.
     *
     * @param int|string $severity
     * @return \Monolog\Level
     */
    protected static function mapSeverityToLevel($severity)
    {
        $int = (int) $severity;
        return self::BETTERSTACK_DEFAULT_LEVELS[$int] ?? \Monolog\Level::Notice;
    }

    /**
     * Costruisce testo messaggio e contesto strutturato da inviare a BetterStack.
     *
     * @param mixed $message
     * @return array{0: string, 1: array}
     */
    protected static function buildContext($message, $error_code, $object_type, $object_id)
    {
        $context = [
            'shop_id' => null,
            'shop_name' => null,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? php_sapi_name(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ];

        try {
            $contextHelper = Context::getContext();
            if ($contextHelper && $contextHelper->shop) {
                $context['shop_id'] = $contextHelper->shop->id;
                $context['shop_name'] = $contextHelper->shop->name;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        if ($error_code !== null && $error_code !== '') {
            $context['error_code'] = $error_code;
        }
        if ($object_type !== null && $object_type !== '') {
            $context['object_type'] = $object_type;
            $context['object_id'] = $object_id;
        }

        if (is_array($message) || (is_object($message) && !method_exists($message, '__toString'))) {
            $context['original_message'] = $message;
            $messageText = (string) json_encode($message, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        } else {
            $messageText = (string) $message;
        }

        return [$messageText, $context];
    }

    /**
     * Effettivo invio su BetterStack.
     *
     * @param mixed $message
     * @param int|string $severity
     */
    protected static function sendToBetterStack($message, $severity, $error_code, $object_type, $object_id)
    {
        if (!self::isBetterStackEnabled()) {
            return;
        }

        $logger = self::getBetterStackLogger();
        if (!$logger) {
            return;
        }

        list($messageText, $context) = self::buildContext($message, $error_code, $object_type, $object_id);
        $level = self::mapSeverityToLevel($severity);

        $logger->log($level, $messageText, $context);
    }
}
