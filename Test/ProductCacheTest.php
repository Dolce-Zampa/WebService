<?php
declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Entities\ProductEntity;
use PS\Webservice\Domain\Object\WebserviceConfig;
use PS\Webservice\Http\Controller\ConfigController;
use PS\Webservice\Http\Middleware\CachingMiddleware;
use PS\Webservice\Service\HttpServiceInterface;
use PS\Webservice\Service\PS\Product;
use PS\Webservice\Service\RedisQueue;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class ProductCacheTest extends TestCase
{
    protected function setUp(): void
    {
        $app = new Container();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        if ($socket = getenv('PRODUCT_CACHE_TEST_REDIS_SOCKET')) {
            $redis = new Predis\Client(['scheme' => 'unix', 'path' => $socket]);
            $redis->flushdb(); // This socket must belong to an isolated test Redis.
            $factory = new class($redis) implements Illuminate\Contracts\Redis\Factory {
                public function __construct(private Predis\Client $redis) {}
                public function connection($name = null) { return $this->redis; }
            };
            $store = new Illuminate\Cache\RedisStore($factory);
        } else {
            $store = new ArrayStore();
        }
        $app->instance('cache', new Repository($store));
        $database = new Illuminate\Database\Capsule\Manager();
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $database->setAsGlobal();
        $database->bootEloquent();
        $database->getConnection()->statement('CREATE TABLE webserviceapi_configurator (id_product INTEGER, active INTEGER, json TEXT)');
        $app->instance('log', new Psr\Log\NullLogger());
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
    }

    private function http(array $payload, int $calls = 1): HttpServiceInterface
    {
        $http = $this->createMock(HttpServiceInterface::class);
        $http->method('getConfig')->willReturn(new WebserviceConfig('https://shop.test/api'));
        $http->expects($this->exactly($calls))->method('invoke')->willReturnSelf();
        $http->method('toArray')->willReturn($payload);
        return $http;
    }

    public function test_category_pages_are_cached_and_pagination_is_isolated(): void
    {
        $service = new Product($this->http(['products' => []], 2));
        $service->getProductByCategory('12', ['page' => 1]);
        $service->getProductByCategory('12', ['page' => 1]);
        $service->getProductByCategory('12', ['page' => 2]);
    }

    public function test_counts_are_cached_and_invalidated_by_catalog_tag(): void
    {
        $service = new Product($this->http(['products' => [['id' => 1]]], 2));
        $this->assertSame(1, $service->countProducts());
        $this->assertSame(1, $service->countProducts());
        Cache::tags(['product-catalog'])->flush();
        $this->assertSame(1, $service->countProducts());
    }

    public function test_upstream_result_is_not_cached_when_invalidated_during_load(): void
    {
        $http = $this->http(['products' => []], 2);
        $http->method('toArray')->willReturnCallback(function () {
            Cache::forever(Product::CACHE_REVISION, bin2hex(random_bytes(16)));
            Cache::tags(['product-catalog'])->flush();
            return ['products' => []];
        });
        $service = new Product($http);
        $service->countProducts();
        $service->countProducts();
    }

    public function test_slug_lookup_is_cached(): void
    {
        $service = new Product($this->http(['data' => ['id_product' => 7]]));
        $this->assertSame(7, $service->findProductIdBySlug('a-product'));
        $this->assertSame(7, $service->findProductIdBySlug('a-product'));
    }

    public function test_complete_snapshot_avoids_http_and_repeated_normalization(): void
    {
        $http = $this->http([], 0);
        $service = $this->getMockBuilder(Product::class)->setConstructorArgs([$http])->onlyMethods(['getProductById'])->getMock();
        $snapshot = ProductEntity::fromSnapshot(['id' => 7, 'price' => 122.0, 'associations' => ['combinations' => [['id' => 9]]]], $service, true);
        $service->expects($this->once())->method('getProductById')->with(7)->willReturn($snapshot);
        $first = $service->getCompleteProductById(7);
        $second = $service->getCompleteProductById(7);
        $this->assertSame(122.0, $second->withFeatures()->getPrice());
        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertNotSame($first, $second);
    }

    public function test_http_hit_preserves_response_body_and_webhook_invalidates_category(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/products');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->exactly(2))->method('handle')->willReturn(response(['products' => [], 'pagination' => ['total' => 0]]));
        $middleware = new CachingMiddleware('products');
        $first = $middleware->process($request, $handler);
        $hit = $middleware->process($request, $handler);
        $this->assertSame('HIT', $hit->getHeaderLine('X-Cache'));
        $this->assertSame((string) $first->getBody(), (string) $hit->getBody());

        $queue = $this->createMock(RedisQueue::class);
        $queue->expects($this->exactly(2))->method('push');
        Facade::getFacadeApplication()->instance('queue-service', $queue);
        $controller = (new ReflectionClass(ConfigController::class))->newInstanceWithoutConstructor();
        $webhook = (new ServerRequestFactory())->createServerRequest('POST', '/api/webhooks/clear-cache')->withParsedBody(['cache' => [['tags' => ['product:7']]]]);
        $this->assertSame(200, $controller->clearCache($webhook, new Response(), [])->getStatusCode());
        $this->assertSame('MISS', $middleware->process($request, $handler)->getHeaderLine('X-Cache'));
    }

    public function test_clear_key_uses_received_tags(): void
    {
        Cache::tags(['custom'])->put(sha1('test-key'), 'value', 300);
        $controller = (new ReflectionClass(ConfigController::class))->newInstanceWithoutConstructor();
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/clear-cache')->withParsedBody(['cache' => [['tags' => ['custom'], 'key' => 'test-key']]]);
        $controller->clearCache($request, new Response(), []);
        $this->assertNull(Cache::tags(['custom'])->get(sha1('test-key')));
    }
    public function test_base_product_cache_does_not_repeat_http_or_vat(): void
    {
        $data = json_decode(file_get_contents(__DIR__ . '/stubs/product-entity.json'), true);
        $service = new Product($this->http(['products' => [$data]]));
        $first = $service->getProductById((int) $data['id']);
        $second = $service->getProductById((int) $data['id']);
        $this->assertSame((float) $data['price'] * 1.22, $first->getPrice());
        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertNotSame($first, $second);
    }

    public function test_unfiltered_category_keeps_all_products(): void
    {
        $first = json_decode(file_get_contents(__DIR__ . '/stubs/product-entity.json'), true);
        $second = $first;
        $second['id'] = 99;
        $second['name'] = 'Second product';
        $service = new Product($this->http(['products' => [$first, $second]]));
        $connection = Illuminate\Database\Capsule\Manager::connection();
        $connection->enableQueryLog();
        $firstLoad = $service->getProductByCategory('19');
        $queryCount = count($connection->getQueryLog());
        $cachedLoad = $service->getProductByCategory('19');
        $this->assertSame([25, 99], $cachedLoad->map(fn ($product) => $product->getId())->all());
        $this->assertSame($firstLoad->toArray(), $cachedLoad->toArray());
        $this->assertSame($queryCount, count($connection->getQueryLog()));
    }

    public function test_http_response_is_not_repopulated_after_webhook_during_load(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/products');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->exactly(2))->method('handle')->willReturnCallback(function () {
            Cache::forever(Product::CACHE_REVISION, bin2hex(random_bytes(16)));
            Cache::tags(['product-catalog'])->flush();
            return response(['products' => []]);
        });
        $middleware = new CachingMiddleware('products');
        $middleware->process($request, $handler);
        $this->assertSame('MISS', $middleware->process($request, $handler)->getHeaderLine('X-Cache'));
    }

}
