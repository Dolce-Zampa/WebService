<?php
declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Http\Controller\InpostPointsController;
use PS\Webservice\Service\PickupPoints\InpostCatalog;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class InpostCatalogTest extends TestCase
{
    private Manager $capsule;

    protected function setUp(): void
    {
        $this->capsule = new Manager();
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $schema = $this->capsule->getConnection()->getSchemaBuilder();
        $schema->create('inpost_sync', function ($t) {
            $t->integer('id')->primary(); $t->dateTime('last_success_at')->nullable(); $t->integer('point_count');
        });
        $schema->create('inpost_points', function ($t) {
            $t->string('point_id')->primary(); $t->string('name'); $t->string('type');
            $t->string('address'); $t->string('city'); $t->string('province')->nullable();
            $t->string('postal_code'); $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable(); $t->text('opening_hours')->nullable();
        });
        $this->db()->table('inpost_sync')->insert(['id' => 1, 'point_count' => 0, 'last_success_at' => null]);
    }

    public function test_complete_snapshot_filters_status_and_searches_locally(): void
    {
        $page = $this->page([$this->point('A'), $this->point('B', 'NonOperating')]);
        self::assertSame(1, $this->catalog([$page, $page])->sync());
        $controller = new InpostPointsController($this->db());
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/')->withQueryParams(['postal_code' => '20100']);
        $response = $controller->index($request, new Response());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $body['data']['total']);
        self::assertSame('A', $body['data']['items'][0]['point_id']);
        self::assertNotNull($body['data']['last_sync_at']);
    }

    public function test_partial_or_changing_snapshot_preserves_previous_data(): void
    {
        $page = $this->page([$this->point('A')]);
        $this->catalog([$page, $page])->sync();
        $incomplete = $this->page([$this->point('B')], 1001, 2);
        try {
            $this->catalog([$incomplete])->sync();
            self::fail('The incomplete snapshot must fail.');
        } catch (RuntimeException $error) {
            self::assertSame('A', $this->db()->table('inpost_points')->value('point_id'));
            self::assertSame(1, $this->db()->table('inpost_sync')->value('point_count'));
        }
    }

    public function test_duplicate_ids_and_changed_first_page_are_rejected(): void
    {
        $duplicate = $this->page([$this->point('A'), $this->point('A')]);
        try { $this->catalog([$duplicate])->sync(); self::fail('Duplicate IDs must fail.'); }
        catch (RuntimeException $error) { self::assertSame(0, $this->db()->table('inpost_points')->count()); }
        $first = $this->page([$this->point('A')]);
        $changed = $this->page([$this->point('B')]);
        try { $this->catalog([$first, $changed])->sync(); self::fail('Changed snapshot must fail.'); }
        catch (RuntimeException $error) { self::assertSame(0, $this->db()->table('inpost_points')->count()); }
    }

    public function test_all_pages_are_imported_and_second_page_failure_preserves_catalog(): void
    {
        $items = [];
        for ($i = 0; $i < 1000; $i++) { $items[] = $this->point('P' . $i); }
        $first = $this->page($items, 1001, 2);
        $second = ['count' => 1001, 'page' => 2, 'total_pages' => 2, 'items' => [$this->point('P1000')]];
        self::assertSame(1001, $this->catalog([$first, $second, $first])->sync());
        self::assertSame(1001, $this->db()->table('inpost_points')->count());
        $partial = $this->page($items, 1001, 2);
        try {
            $this->catalog([$partial, ['count' => 1001, 'page' => 2, 'total_pages' => 2, 'items' => []]])->sync();
            self::fail('Missing records must fail.');
        } catch (RuntimeException $error) {
            self::assertSame(1001, $this->db()->table('inpost_points')->count());
        }
    }

    public function test_absent_or_stale_catalog_and_bad_query_are_explicit(): void
    {
        $controller = new InpostPointsController($this->db());
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/')->withQueryParams(['city' => 'Milano']);
        self::assertSame(503, $controller->index($request, new Response())->getStatusCode());
        $this->db()->table('inpost_sync')->update(['last_success_at' => '2020-01-01 00:00:00']);
        self::assertSame(503, $controller->index($request, new Response())->getStatusCode());
        self::assertSame(422, $controller->index($request->withQueryParams(['postal_code' => 'bad']), new Response())->getStatusCode());
    }

    public function test_temporary_server_error_is_retried(): void
    {
        $page = $this->page([$this->point('A')]);
        $ok = fn () => new GuzzleResponse(200, [], json_encode($page, JSON_THROW_ON_ERROR));
        $http = new Client(['handler' => HandlerStack::create(new MockHandler([new GuzzleResponse(503), $ok(), $ok()]))]);
        self::assertSame(1, (new InpostCatalog($http, $this->db()))->sync());
    }

    public function test_checkout_uses_the_server_point_and_preserves_invoice_address(): void
    {
        $page = $this->page([$this->point('A')]);
        $this->catalog([$page, $page])->sync();
        $checkout = new \PS\Webservice\Service\PickupPoints\InpostCheckout($this->db(), [17]);
        $payload = $checkout->prepare(['inpost_point_id' => 'A', 'delivery_address' => ['address1' => 'forged', 'country' => 'IT'],
            'invoice_address' => ['address1' => 'Customer']], 17);
        self::assertSame('Via Roma 1', $payload['delivery_address']['address1']);
        self::assertSame('InPost A', $payload['delivery_address']['address2']);
        self::assertSame('Customer', $payload['invoice_address']['address1']);
        $this->expectException(InvalidArgumentException::class);
        $checkout->prepare(['inpost_point_id' => 'A'], 18);
    }

    public function test_checkout_rejects_missing_unavailable_and_stale_point(): void
    {
        $page = $this->page([$this->point('A')]);
        $this->catalog([$page, $page])->sync();
        $checkout = new \PS\Webservice\Service\PickupPoints\InpostCheckout($this->db(), [17]);
        foreach ([['delivery_address' => ['country' => 'IT']], ['inpost_point_id' => 'missing', 'delivery_address' => ['country' => 'IT']]] as $payload) {
            try { $checkout->prepare($payload, 17); self::fail('Invalid selection must be rejected.'); }
            catch (InvalidArgumentException $error) { self::assertNotSame('', $error->getMessage()); }
        }
        $this->db()->table('inpost_sync')->update(['last_success_at' => '2020-01-01 00:00:00']);
        $this->expectException(RuntimeException::class);
        $checkout->prepare(['inpost_point_id' => 'A', 'delivery_address' => ['country' => 'IT']], 17);
    }

    public function test_webhook_preserves_the_selected_pickup_address(): void
    {
        $class = new ReflectionClass(\PS\Webservice\Http\Controller\StripeWebhookController::class);
        $controller = $class->newInstanceWithoutConstructor();
        $method = $class->getMethod('getCheckoutCustomerFromSnapshot');
        $customer = ['email' => 'test@example.com', 'firstname' => 'Test', 'lastname' => 'Buyer',
            'delivery_address' => ['address1' => 'Via Locker', 'city' => 'Milano', 'postcode' => '20100']];
        $stripe = \Stripe\Checkout\Session::constructFrom(['shipping_details' => ['address' => [
            'line1' => 'Via Cliente', 'city' => 'Roma', 'postal_code' => '00100', 'country' => 'IT']]]);
        $result = $method->invoke($controller, ['customer' => $customer, 'inpost_point_id' => 'A'], $stripe);
        self::assertSame('Via Locker', $result->delivery_address['address1']);
        $normal = $method->invoke($controller, ['customer' => $customer], $stripe);
        self::assertSame('Via Cliente', $normal->delivery_address['address1']);
    }

    private function db(): \Illuminate\Database\ConnectionInterface { return $this->capsule->getConnection(); }
    private function catalog(array $pages): InpostCatalog
    {
        $responses = array_map(fn ($page) => new GuzzleResponse(200, [], json_encode($page, JSON_THROW_ON_ERROR)), $pages);
        return new InpostCatalog(new Client(['handler' => HandlerStack::create(new MockHandler($responses))]), $this->db());
    }
    private function page(array $items, ?int $count = null, int $pages = 1): array
    { return ['count' => $count ?? count($items), 'page' => 1, 'total_pages' => $pages, 'items' => $items]; }
    private function point(string $id, string $status = 'Operating'): array
    {
        return ['name' => $id, 'country' => 'IT', 'status' => $status, 'type' => ['parcel_locker'],
            'address' => ['line1' => 'Via Roma 1'], 'address_details' => ['city' => 'Milano', 'post_code' => '20100'],
            'location' => ['latitude' => 45.4, 'longitude' => 9.2]];
    }
}
