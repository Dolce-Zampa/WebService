<?php

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$originEnv = explode("|",env("CORS_ALLOWED_DOMAINS", ""));
$allowedOrigins = [
	'http://localhost:3000',
	'http://127.0.0.1:3000',
];

$allowedOrigins = array_merge($allowedOrigins, $originEnv);

if (in_array($origin, $allowedOrigins, true)) {
	header('Access-Control-Allow-Origin: ' . $origin);
	header('Vary: Origin');
	header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
	http_response_code(204);
	exit;
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