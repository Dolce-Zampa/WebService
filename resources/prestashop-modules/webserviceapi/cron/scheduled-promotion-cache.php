<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require dirname(__DIR__, 3) . '/config/config.inc.php';
require_once __DIR__ . '/../classes/ScheduledPromotionCacheScan.php';
try {
    $count = ScheduledPromotionCacheScan::run();
    echo "Promotion cache scan completed: {$count} product(s).\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[webserviceapi] ' . $e->getMessage() . "\n");
    exit(1);
}
