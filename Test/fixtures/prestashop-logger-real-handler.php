<?php

require __DIR__ . '/../../vendor/autoload.php';

class PrestaShopLoggerCore
{
    public static function addLog($message, $severity = 1, $errorCode = null, $objectType = null, $objectId = null, $allowDuplicate = false, $idEmployee = null)
    {
        return true;
    }
}

class Configuration
{
    public static function get($name)
    {
        return null;
    }
}

require __DIR__ . '/../../resources/overrides/classes/PrestaShopLogger.php';

putenv('LOGTAIL_API_KEY=test-token');
putenv('LOGTAIL_ENDPOINT=https://example.invalid');
$method = new ReflectionMethod(PrestaShopLogger::class, 'getBetterStackLogger');
$logger = $method->invoke(null);
if (!$logger instanceof Monolog\Logger || !($logger->getHandlers()[0] ?? null) instanceof Logtail\Monolog\LogtailHandler) {
    fwrite(STDERR, "Real Logtail handler could not be constructed\n");
    exit(1);
}

echo "Real Logtail handler constructed\n";
