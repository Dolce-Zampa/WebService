<?php

declare(strict_types=1);

use DI\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Http\Controller\WishlistController;
use PS\Webservice\Service\Financial\EmployeeFinancialAuthService;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

require_once __DIR__ . '/../src/Http/Controller/WishlistController.php';
require_once __DIR__ . '/../src/Http/Middleware/WishlistAuthenticationMiddleware.php';

/** Exercises the registered HTTP routes, middleware, JSON body parsing and real controller over an isolated SQLite fixture. */
final class WishlistHttpIntegrationTest extends TestCase
{
    private App $app;
    private Manager $database;
    private mixed $previousFacadeApplication;
    private array $previousAuthEnvironment = [];

    protected function setUp(): void
    {
        // Authentication uses strict Cognito claim validation. Supply an isolated
        // test configuration instead of depending on the developer's .env file.
        foreach (['AWS_DEFAULT_REGION' => 'eu-west-1', 'AWS_COGNITO_USER_POOL_ID' => 'fixture-pool', 'AWS_COGNITO_CLIENT_ID' => 'fixture-client'] as $key => $value) {
            $this->previousAuthEnvironment[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication([
            'aws-cognito-client' => new class {
                public function decodeAccessToken(string $token): array {
                    $claims = [
                        'sub' => $token === 'bob-token' ? 'bob' : 'alice',
                        'iss' => 'https://cognito-idp.' . env('AWS_DEFAULT_REGION', 'eu-west-1') . '.amazonaws.com/' . env('AWS_COGNITO_USER_POOL_ID'),
                        'client_id' => env('AWS_COGNITO_CLIENT_ID'), 'token_use' => 'access', 'exp' => time() + 3600,
                    ];
                    return match ($token) {
                        'alice-token', 'bob-token' => $claims,
                        'expired-token' => array_replace($claims, ['exp' => time() - 1]),
                        'id-token' => array_replace($claims, ['token_use' => 'id']),
                        'other-client-token' => array_replace($claims, ['client_id' => 'other-client']),
                        'other-issuer-token' => array_replace($claims, ['iss' => 'https://example.invalid']),
                        'not-yet-valid-token' => array_replace($claims, ['nbf' => time() + 3600]),
                        default => [],
                    };
                }
            },
            'log' => new class { public function error(string $message, array $context = []): void {} },
        ]);
        $this->database = new Manager();
        $socket = getenv('WISHLIST_TEST_MYSQL_SOCKET');
        if ($socket !== false && $socket !== '') {
            $database = getenv('WISHLIST_TEST_MYSQL_DATABASE') ?: '';
            if (!preg_match('/\Adz_wishlist_test_[a-z0-9_]+\z/', $database)) {
                throw new RuntimeException('Use an isolated database named dz_wishlist_test_<suffix>.');
            }
            $this->database->addConnection(['driver' => 'mysql', 'host' => 'localhost', 'unix_socket' => $socket,
                'database' => $database, 'username' => getenv('WISHLIST_TEST_MYSQL_USER') ?: 'root',
                'password' => getenv('WISHLIST_TEST_MYSQL_PASSWORD') ?: '', 'charset' => 'utf8mb4', 'prefix' => '']);
        } else {
            $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        }
        $db = $this->database->getConnection();
        if ($socket) {
            foreach (['customer', 'product', 'product_attribute', 'product_shop', 'stock_available', 'shop', 'configuration'] as $table) $db->getSchemaBuilder()->dropIfExists($table);
            $db->table('dz_wishlist_item')->delete();
            $db->table('dz_wishlist')->delete();
        }
        foreach ([
            'CREATE TABLE customer (id_customer INTEGER PRIMARY KEY, sub TEXT)',
            'CREATE TABLE product (id_product INTEGER PRIMARY KEY)',
            'CREATE TABLE product_attribute (id_product_attribute INTEGER PRIMARY KEY, id_product INTEGER)',
            'CREATE TABLE product_shop (id_product INTEGER, id_shop INTEGER, active INTEGER, available_for_order INTEGER)',
            'CREATE TABLE stock_available (id_product INTEGER, id_product_attribute INTEGER, id_shop INTEGER, id_shop_group INTEGER, quantity INTEGER, out_of_stock INTEGER)',
            'CREATE TABLE shop (id_shop INTEGER, id_shop_group INTEGER)',
            'CREATE TABLE configuration (id_configuration INTEGER PRIMARY KEY, name TEXT, value TEXT)',
        ] as $statement) $db->statement($statement);
        if (!$socket) {
            $db->statement('CREATE TABLE dz_wishlist (customer_id INTEGER PRIMARY KEY, name TEXT, share_token TEXT UNIQUE, created_at TEXT, updated_at TEXT)');
            $db->statement('CREATE TABLE dz_wishlist_item (customer_id INTEGER, product_id INTEGER, product_attribute_id INTEGER, created_at TEXT, PRIMARY KEY(customer_id, product_id, product_attribute_id))');
        }
        $db->table('customer')->insert([['id_customer' => 1, 'sub' => 'alice'], ['id_customer' => 2, 'sub' => 'bob']]);
        $db->table('product')->insert([['id_product' => 5], ['id_product' => 6]]);
        $db->table('product_attribute')->insert(['id_product_attribute' => 12, 'id_product' => 5]);
        $db->table('product_shop')->insert([
            ['id_product' => 5, 'id_shop' => 1, 'active' => 1, 'available_for_order' => 1],
            ['id_product' => 6, 'id_shop' => 1, 'active' => 0, 'available_for_order' => 1],
        ]);
        $db->table('shop')->insert(['id_shop' => 1, 'id_shop_group' => 1]);
        $db->table('stock_available')->insert(['id_product' => 5, 'id_product_attribute' => 12, 'id_shop' => 1, 'id_shop_group' => 0, 'quantity' => 3, 'out_of_stock' => 0]);

        $container = new Container();
        $container->set(WishlistController::class, new WishlistController($db));
        $container->set(EmployeeFinancialAuthService::class, new EmployeeFinancialAuthService($this->database, 'test-secret'));
        $app = AppFactory::createFromContainer($container);
        $app->addBodyParsingMiddleware();
        require __DIR__ . '/../routes/api.php';
        $this->app = $app;
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        foreach ($this->previousAuthEnvironment as $key => [$env, $server]) {
            if ($env === null) unset($_ENV[$key]); else $_ENV[$key] = $env;
            if ($server === null) unset($_SERVER[$key]); else $_SERVER[$key] = $server;
        }
    }

    private function request(string $method, string $path, ?string $token = null, ?array $body = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path)->withHeader('Content-Type', 'application/json');
        if ($token !== null) $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        if ($body !== null) $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));
        return $this->app->handle($request);
    }

    private function data(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['data'];
    }

    public function test_personal_and_shared_routes_reflect_the_same_changes(): void
    {
        $initial = $this->request('GET', '/api/wishlists', 'alice-token');
        self::assertSame(200, $initial->getStatusCode());
        self::assertSame('no-store', $initial->getHeaderLine('Cache-Control'));
        $list = $this->data($initial)['wishlist'];
        self::assertSame([], $list['products']);
        $token = $list['share_token'];
        $sharedUrl = '/api/wishlists/shared/' . $token;
        $shortUrl = '/api/wishlists/shared/' . $list['short_token'];

        $this->request('PUT', '/api/wishlists', 'alice-token', ['name' => 'Compleanno']);
        $added = $this->request('POST', '/api/wishlists', 'alice-token', ['id_customer' => 2, 'id_product' => 5, 'id_product_attribute' => 12]);
        self::assertSame(200, $added->getStatusCode());
        $this->request('POST', '/api/wishlists', 'alice-token', ['id_product' => 5, 'id_product_attribute' => 12]);
        $shared = $this->request('GET', $sharedUrl);
        self::assertSame(200, $shared->getStatusCode());
        self::assertSame('no-referrer', $shared->getHeaderLine('Referrer-Policy'));
        $sharedList = $this->data($shared)['wishlist'];
        self::assertSame('Compleanno', $sharedList['name']);
        self::assertCount(1, $sharedList['products']);
        self::assertTrue($sharedList['products'][0]['available']);
        self::assertArrayNotHasKey('share_token', $sharedList);
        self::assertSame($sharedList, $this->data($this->request('GET', $shortUrl))['wishlist']);
        self::assertArrayNotHasKey('customer_id', $sharedList);
        self::assertSame([], $this->data($this->request('GET', '/api/wishlists', 'bob-token'))['wishlist']['products']);

        $this->database->getConnection()->table('product_shop')->where('id_product', 5)->update(['active' => 0]);
        self::assertFalse($this->data($this->request('GET', $sharedUrl))['wishlist']['products'][0]['available']);
        self::assertSame(200, $this->request('DELETE', '/api/wishlists', 'alice-token', ['id_product' => 5, 'id_product_attribute' => 12])->getStatusCode());
        self::assertSame([], $this->data($this->request('GET', $sharedUrl))['wishlist']['products']);
    }

    public function test_rejects_unauthorized_mutations_and_invalid_resources(): void
    {
        foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) {
            self::assertSame(401, $this->request($method, '/api/wishlists')->getStatusCode());
            self::assertSame(401, $this->request($method, '/api/wishlists', 'invalid-token')->getStatusCode());
        }
        foreach (['expired-token', 'id-token', 'other-client-token', 'other-issuer-token', 'not-yet-valid-token'] as $token) {
            self::assertSame(401, $this->request('GET', '/api/wishlists', $token)->getStatusCode());
            self::assertSame(401, $this->request('POST', '/api/wishlists', $token, ['id_product' => 5])->getStatusCode());
        }
        self::assertSame(404, $this->request('GET', '/api/wishlists/shared/' . str_repeat('0', 64))->getStatusCode());
        self::assertSame(404, $this->request('POST', '/api/wishlists', 'alice-token', ['id_product' => 6])->getStatusCode());
        self::assertSame(404, $this->request('POST', '/api/wishlists', 'alice-token', ['id_product' => 5, 'id_product_attribute' => 99])->getStatusCode());
        self::assertSame(422, $this->request('POST', '/api/wishlists', 'alice-token', ['id_product' => '5'])->getStatusCode());
        $token = $this->data($this->request('GET', '/api/wishlists', 'alice-token'))['wishlist']['share_token'];
        try {
            $this->request('DELETE', '/api/wishlists/shared/' . $token, 'bob-token', ['id_product' => 5]);
            self::fail('Shared route must not accept DELETE.');
        } catch (\Slim\Exception\HttpMethodNotAllowedException $exception) {
            self::assertSame(405, $exception->getCode());
        }
    }
}
