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
        $app->instance('cache', new Repository(new ArrayStore()));
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
}
