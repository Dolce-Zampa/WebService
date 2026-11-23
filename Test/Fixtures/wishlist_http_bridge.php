<?php

declare(strict_types=1);

// Test-only bridge: the database is created in a fresh temporary directory and
// the two bearer tokens below map to fixture users. No external service is used.
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Http/Controller/WishlistController.php';
require_once __DIR__ . '/../../src/Http/Middleware/WishlistAuthenticationMiddleware.php';
foreach (['AWS_DEFAULT_REGION' => 'eu-west-1', 'AWS_COGNITO_USER_POOL_ID' => 'fixture-pool', 'AWS_COGNITO_CLIENT_ID' => 'fixture-client'] as $key => $value) { $_ENV[$key] = $value; $_SERVER[$key] = $value; }

use DI\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\Facade;
use PS\Webservice\Http\Controller\WishlistController;
use PS\Webservice\Service\Financial\EmployeeFinancialAuthService;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$dbPath = getenv('WISHLIST_FIXTURE_DATABASE');
if (!$dbPath || !is_file($dbPath) || basename($dbPath) !== 'fixture.sqlite'
    || !str_starts_with(basename(dirname($dbPath)), 'wishlist-cross-stack-')) {
    throw new RuntimeException('A dedicated wishlist-cross-stack fixture database is required.');
}
$database = new Manager();
$database->addConnection(['driver' => 'sqlite', 'database' => $dbPath, 'prefix' => '']);
$db = $database->getConnection();
if (!$db->getSchemaBuilder()->hasTable('customer')) {
    foreach ([
        'CREATE TABLE customer (id_customer INTEGER PRIMARY KEY, sub TEXT)',
        'CREATE TABLE product (id_product INTEGER PRIMARY KEY)',
        'CREATE TABLE product_attribute (id_product_attribute INTEGER PRIMARY KEY, id_product INTEGER)',
        'CREATE TABLE product_shop (id_product INTEGER, id_shop INTEGER, active INTEGER, available_for_order INTEGER)',
        'CREATE TABLE stock_available (id_product INTEGER, id_product_attribute INTEGER, id_shop INTEGER, id_shop_group INTEGER, quantity INTEGER, out_of_stock INTEGER)',
        'CREATE TABLE shop (id_shop INTEGER, id_shop_group INTEGER)',
        'CREATE TABLE configuration (id_configuration INTEGER PRIMARY KEY, name TEXT, value TEXT)',
        'CREATE TABLE dz_wishlist (customer_id INTEGER PRIMARY KEY, name TEXT, share_token TEXT UNIQUE, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE dz_wishlist_item (customer_id INTEGER, product_id INTEGER, product_attribute_id INTEGER, created_at TEXT, PRIMARY KEY(customer_id, product_id, product_attribute_id))',
    ] as $statement) $db->statement($statement);
    $db->table('customer')->insert([['id_customer' => 1, 'sub' => 'alice'], ['id_customer' => 2, 'sub' => 'bob']]);
    $db->table('product')->insert(['id_product' => 245]);
    $db->table('product_attribute')->insert(['id_product_attribute' => 42, 'id_product' => 245]);
    $db->table('product_shop')->insert(['id_product' => 245, 'id_shop' => 1, 'active' => 1, 'available_for_order' => 1]);
    $db->table('stock_available')->insert(['id_product' => 245, 'id_product_attribute' => 42, 'id_shop' => 1, 'id_shop_group' => 0, 'quantity' => 3, 'out_of_stock' => 0]);
    $db->table('shop')->insert(['id_shop' => 1, 'id_shop_group' => 1]);
}
if (($input['fixture'] ?? '') === 'disable-product') {
    $db->table('product_shop')->where('id_product', 245)->update(['active' => 0]);
    echo json_encode(['status' => 200, 'body' => []], JSON_THROW_ON_ERROR);
    exit;
}
Facade::clearResolvedInstances();
Facade::setFacadeApplication([
    'aws-cognito-client' => new class {
        public function decodeAccessToken(string $token): array {
            $sub = match ($token) { 'alice-token' => 'alice', 'bob-token' => 'bob', default => null };
            return $sub === null ? [] : ['sub' => $sub, 'iss' => 'https://cognito-idp.eu-west-1.amazonaws.com/fixture-pool',
                'client_id' => 'fixture-client', 'token_use' => 'access', 'exp' => time() + 3600];
        }
    },
    'log' => new class { public function error(string $message, array $context = []): void {} },
]);
$container = new Container();
$container->set(WishlistController::class, new WishlistController($db));
$container->set(EmployeeFinancialAuthService::class, new EmployeeFinancialAuthService($database, 'test-secret'));
$app = AppFactory::createFromContainer($container);
$app->addBodyParsingMiddleware();
require __DIR__ . '/../../routes/api.php';
$request = (new ServerRequestFactory())->createServerRequest($input['method'], $input['path'])->withHeader('Content-Type', 'application/json');
if (!empty($input['authorization'])) $request = $request->withHeader('Authorization', $input['authorization']);
if (isset($input['body'])) $request->getBody()->write($input['body']);
try {
    $response = $app->handle($request);
    echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getBody(), true)], JSON_THROW_ON_ERROR);
} catch (\Slim\Exception\HttpException $exception) {
    echo json_encode(['status' => $exception->getCode(), 'body' => []], JSON_THROW_ON_ERROR);
}
