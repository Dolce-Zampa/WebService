<?php
declare(strict_types=1);

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Entities\ProductEntity;
use PS\Webservice\Http\Controller\ProductController;
use PS\Webservice\Service\ElkService;
use PS\Webservice\Service\OpenAIService;
use PS\Webservice\Service\Promotions\PromotionService;
use PS\Webservice\Service\PS\Product;
use PS\Webservice\Service\RedisQueue;
use PS\Webservice\Commands\ElasticSearch\IndexBulkProduct;
use PS\Webservice\Commands\ElasticSearch\AuditProductIndex;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Symfony\Component\Console\Tester\CommandTester;

final class ElkSearchHotfixTest extends TestCase
{
    private array $requests = [];
    private mixed $previousFacadeApplication;
    private mixed $previousDatabase;

    private function client(array $responses): Client
    {
        $mock = new MockHandler(array_map(static fn (array $body) => new GuzzleHttp\Psr7\Response(200, [
            'Content-Type' => 'application/json', 'X-Elastic-Product' => 'Elasticsearch',
        ], json_encode($body)), $responses));
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->requests));
        return ClientBuilder::create()->setHosts(['http://localhost:9200'])->setHttpClient(new GuzzleHttp\Client(['handler' => $stack]))->build();
    }

    protected function setUp(): void
    {
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousDatabase = (new ReflectionProperty(Illuminate\Database\Capsule\Manager::class, 'instance'))->getValue();
        $app = new Container();
        $app->instance('log', new Psr\Log\NullLogger());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        (new ReflectionProperty(Illuminate\Database\Capsule\Manager::class, 'instance'))->setValue(null, $this->previousDatabase);
    }

    private function product(Product $service, int $id, int $active = 1): ProductEntity
    {
        return ProductEntity::fromSnapshot([
            'id' => $id, 'name' => 'Pettorina ' . $id, 'description' => 'Test',
            'description_short' => 'Test', 'price' => 10, 'active' => $active,
            'id_category_default' => 3, 'id_manufacturer' => 1, 'id_supplier' => 1,
            'associations' => ['categories' => [['id' => 3]], 'product_features' => [['id' => 2]]],
        ], $service);
    }

    public function test_bulk_sends_separate_action_and_document_and_removes_inactive(): void
    {
        $products = $this->createMock(Product::class);
        $products->method('getProductById')->willReturnCallback(fn (int $id) => $id === 12 ? null : $this->product($products, $id));
        $client = $this->client([['errors' => true, 'items' => [['index' => ['_id' => '11', 'status' => 201]], ['delete' => ['_id' => '12', 'status' => 404]]]]]);
        (new ElkService($client, $products))->bulkIndexProducts([11, 12]);
        $lines = explode("\n", trim((string) $this->requests[0]['request']->getBody()));
        self::assertCount(3, $lines);
        self::assertSame(['index' => ['_id' => '11']], json_decode($lines[0], true));
        self::assertSame(11, json_decode($lines[1], true)['id_product']);
        self::assertSame(['delete' => ['_id' => '12']], json_decode($lines[2], true));
    }

    public function test_bulk_reports_individual_failures(): void
    {
        $products = $this->createMock(Product::class);
        $products->method('getProductById')->willReturn($this->product($products, 11));
        $client = $this->client([['errors' => true, 'items' => [['index' => ['_id' => '11', 'status' => 400, 'error' => ['type' => 'mapper_parsing_exception']]]]]]);
        $this->expectExceptionMessage('indexing failed for product IDs: 11');
        (new ElkService($client, $products))->bulkIndexProducts([11]);
    }

    public function test_search_reads_all_scroll_pages_and_both_document_shapes(): void
    {
        $products = $this->createMock(Product::class);
        $products->expects($this->never())->method('getProductById');
        $hits = [];
        for ($id = 1; $id <= 11; $id++) {
            $hits[] = ['_source' => ['id_product' => $id, 'name' => 'Pettorina ' . $id, 'price' => 10, 'url' => '/product/' . $id, 'active' => true, 'id_default_image' => $id + 100]];
        }
        $client = $this->client([
            ['_scroll_id' => 'first', 'hits' => ['hits' => $hits]],
            ['_scroll_id' => 'second', 'hits' => ['hits' => [['_source' => ['data' => ['id_product' => 11, 'name' => 'Pettorina 11', 'price' => 10, 'url' => '/product/11', 'active' => true]]], ['_source' => ['data' => ['id_product' => 12, 'name' => 'Pettorina 12', 'price' => 10, 'url' => '/product/12', 'active' => false]]]]]],
            ['_scroll_id' => 'last', 'hits' => ['hits' => []]],
            ['succeeded' => true],
        ]);
        $result = (new ElkService($client, $products))->searchProductsByName('pettorina');
        self::assertCount(11, $result);
        self::assertSame(11, $result->last()->getId());
        self::assertSame([['id' => 101]], $result->first()->toArray()['associations']['images']);
        self::assertSame(200, json_decode((string) $this->requests[0]['request']->getBody(), true)['size']);
        self::assertSame(['scroll_id' => ['last']], json_decode((string) $this->requests[3]['request']->getBody(), true));
    }

    public function test_controller_never_searches_prestashop_when_elasticsearch_succeeds(): void
    {
        $products = $this->createMock(Product::class);
        $elk = $this->createMock(ElkService::class);
        $elk->method('searchProductsByName')->willReturn(collect([$this->product($products, 1)]));
        $products->expects($this->never())->method('searchProducts');
        $controller = new ProductController($products, $this->createMock(PromotionService::class), $elk, $this->createMock(OpenAIService::class));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/search')->withQueryParams(['q' => 'pettorina']);
        $response = $controller->searchProducts($request, new Response());
        $result = json_decode((string) $response->getBody(), true);
        self::assertSame([1], array_column($result['data'], 'id'));
    }

    public function test_controller_uses_prestashop_if_elasticsearch_fails(): void
    {
        $products = $this->createMock(Product::class);
        $elk = $this->createMock(ElkService::class);
        $elk->method('searchProductsByName')->willThrowException(new RuntimeException('unavailable'));
        $products->method('searchProducts')->willReturn(collect([$this->product($products, 2)]));
        $controller = new ProductController($products, $this->createMock(PromotionService::class), $elk, $this->createMock(OpenAIService::class));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/search')->withQueryParams(['q' => 'pettorina']);
        $result = json_decode((string) $controller->searchProducts($request, new Response())->getBody(), true);
        self::assertSame([2], array_column($result['data'], 'id'));
    }

    public function test_controller_does_not_fallback_for_empty_elasticsearch_results(): void
    {
        $products = $this->createMock(Product::class);
        $elk = $this->createMock(ElkService::class);
        $elk->method('searchProductsByName')->willReturn(collect());
        $products->expects($this->never())->method('searchProducts');
        $controller = new ProductController($products, $this->createMock(PromotionService::class), $elk, $this->createMock(OpenAIService::class));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/search')->withQueryParams(['q' => 'pettorina']);
        $result = json_decode((string) $controller->searchProducts($request, new Response())->getBody(), true);
        self::assertSame([], $result['data']);
    }

    public function test_all_command_queues_every_active_product_in_batches(): void
    {
        $database = new Illuminate\Database\Capsule\Manager();
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $database->setAsGlobal();
        $database->bootEloquent();
        $database->getConnection()->statement('CREATE TABLE product (id_product INTEGER PRIMARY KEY, active INTEGER)');
        foreach ([1 => 1, 2 => 0, 3 => 1, 4 => 1] as $id => $active) {
            $database->getConnection()->table('product')->insert(['id_product' => $id, 'active' => $active]);
        }
        $queued = [];
        $queue = $this->createMock(RedisQueue::class);
        $queue->method('push')->willReturnCallback(function (string $name, array $payload) use (&$queued): void {
            self::assertSame(ElkService::QUEUE_NAME, $name);
            $queued[] = $payload;
        });
        $client = $this->client([]);
        $elk = new ElkService($client, $this->createMock(Product::class));
        $command = new CommandTester(new IndexBulkProduct($client, $elk, $queue));
        self::assertSame(0, $command->execute(['--all' => true, '--chunk' => 2]));
        self::assertSame([['product_ids' => [1, 3]], ['product_ids' => [4]]], $queued);
    }

    public function test_audit_identifies_missing_extra_duplicate_and_invalid_documents(): void
    {
        $database = new Illuminate\Database\Capsule\Manager();
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $database->setAsGlobal();
        $database->bootEloquent();
        $database->getConnection()->statement('CREATE TABLE product (id_product INTEGER PRIMARY KEY, active INTEGER)');
        foreach ([1 => 1, 2 => 1, 3 => 1, 4 => 0] as $id => $active) {
            $database->getConnection()->table('product')->insert(['id_product' => $id, 'active' => $active]);
        }
        $doc = static fn (int $id, bool $active): array => ['id_product' => $id, 'name' => 'Prodotto ' . $id, 'price' => 10, 'url' => '/product/' . $id, 'active' => $active];
        $client = $this->client([
            ['_scroll_id' => 'audit', 'hits' => ['hits' => [
                ['_source' => $doc(1, true)], ['_source' => ['data' => $doc(1, true)]],
                ['_source' => $doc(3, false)], ['_source' => $doc(4, true)], ['_source' => ['id_product' => 2]],
            ]]],
            ['_scroll_id' => 'audit', 'hits' => ['hits' => []]], ['succeeded' => true],
        ]);
        $command = new CommandTester(new AuditProductIndex($client));
        self::assertSame(1, $command->execute([]));
        $result = json_decode($command->getDisplay(), true);
        self::assertSame(3, $result['active_catalog_count']);
        self::assertSame([2, 3], $result['missing_ids']);
        self::assertSame([4], $result['extra_active_ids']);
        self::assertSame([1], $result['duplicate_ids']);
        self::assertSame(1, $result['invalid_documents']);
    }

    public function test_category_indexing_continues_after_first_page(): void
    {
        $database = new Illuminate\Database\Capsule\Manager();
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $database->setAsGlobal();
        $database->getConnection()->statement('CREATE TABLE category_product (id_category INTEGER, id_product INTEGER)');
        foreach (range(1, 101) as $id) {
            $database->getConnection()->table('category_product')->insert(['id_category' => 3, 'id_product' => $id]);
        }
        $products = $this->createMock(Product::class);
        $products->method('getProductById')->willReturnCallback(fn (int $id) => $this->product($products, $id));
        $items = array_map(static fn ($id) => ['index' => ['_id' => (string) $id, 'status' => 201]], range(1, 100));
        $client = $this->client([['errors' => false, 'items' => $items], ['errors' => false, 'items' => [['index' => ['_id' => '101', 'status' => 201]]]]]);
        (new ElkService($client, $products))->bulkIndexCategory(3);
        self::assertCount(2, $this->requests);
        $lastBatch = explode("\n", trim((string) $this->requests[1]['request']->getBody()));
        self::assertSame(['index' => ['_id' => '101']], json_decode($lastBatch[0], true));
    }
}
