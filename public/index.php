<?php
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
// all subdomains of dolcezampa.com are allowed
if (preg_match('/^https?:\/\/([a-z0-9-]+\.)?dolcezampa\.com$/', $origin)) {
	header('Access-Control-Allow-Origin: ' . $origin);
	header('Vary: Origin');
	header('Access-Control-Allow-Credentials: true');
	header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
	header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin');
}

# --- NUOVO: INTERCETTA E FERMA IL PREFLIGHT OPTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("HTTP/1.1 200 OK");
    exit(0);
}

require_once __DIR__ . "/../bootstrap/app.php";

$app = \Slim\Factory\AppFactory::createFromContainer($container);

/**
 * The routing middleware should be added earlier than the ErrorMiddleware
 * Otherwise exceptions thrown from it will not be handled by the middleware
 */
require_once __DIR__ . "/../config/middleware.php";

require_once __DIR__ . "/../routes/api.php";

// Run app
$app->run();