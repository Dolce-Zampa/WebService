<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Http\Controller\WishlistController;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

require_once __DIR__ . '/../src/Http/Controller/WishlistController.php';

final class WishlistApiTest extends TestCase
{
    private Manager $db;
    private WishlistController $controller;

    protected function setUp(): void
    {
        $this->db = new Manager();
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $db = $this->db->getConnection();
        $db->statement('CREATE TABLE customer (id_customer INTEGER PRIMARY KEY, sub TEXT)');
        $db->statement('CREATE TABLE product (id_product INTEGER PRIMARY KEY, active INTEGER, available_for_order INTEGER)');
        $db->statement('CREATE TABLE product_attribute (id_product_attribute INTEGER PRIMARY KEY, id_product INTEGER)');
        $db->statement('CREATE TABLE product_shop (id_product INTEGER, id_shop INTEGER, active INTEGER, available_for_order INTEGER)');
        $db->statement('CREATE TABLE stock_available (id_product INTEGER, id_product_attribute INTEGER, id_shop INTEGER, id_shop_group INTEGER, quantity INTEGER, out_of_stock INTEGER)');
        $db->statement('CREATE TABLE shop (id_shop INTEGER, id_shop_group INTEGER)');
        $db->statement('CREATE TABLE configuration (id_configuration INTEGER PRIMARY KEY, name TEXT, value TEXT)');
        $db->statement('CREATE TABLE dz_wishlist (customer_id INTEGER PRIMARY KEY, name TEXT, share_token TEXT UNIQUE, created_at TEXT, updated_at TEXT)');
        $db->statement('CREATE TABLE dz_wishlist_item (customer_id INTEGER, product_id INTEGER, product_attribute_id INTEGER, created_at TEXT, PRIMARY KEY(customer_id, product_id, product_attribute_id))');
        $db->table('customer')->insert([['id_customer' => 1, 'sub' => 'alice'], ['id_customer' => 2, 'sub' => 'bob']]);
        $db->table('product')->insert([['id_product' => 5, 'active' => 1, 'available_for_order' => 1], ['id_product' => 6, 'active' => 0, 'available_for_order' => 1]]);
        $db->table('product_attribute')->insert(['id_product_attribute' => 12, 'id_product' => 5]);
        $db->table('product_shop')->insert([['id_product' => 5, 'id_shop' => 1, 'active' => 1, 'available_for_order' => 1], ['id_product' => 6, 'id_shop' => 1, 'active' => 0, 'available_for_order' => 1]]);
        $db->table('shop')->insert(['id_shop' => 1, 'id_shop_group' => 1]);
        $db->table('stock_available')->insert([['id_product' => 5, 'id_product_attribute' => 0, 'id_shop' => 1, 'id_shop_group' => 0, 'quantity' => 5, 'out_of_stock' => 0], ['id_product' => 5, 'id_product_attribute' => 12, 'id_shop' => 1, 'id_shop_group' => 0, 'quantity' => 0, 'out_of_stock' => 0]]);
        $this->controller = new WishlistController($db);
    }

    private function request(string $method, ?string $sub = 'alice', mixed $body = null): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, '/')
            ->withAttribute('user_id', $sub)->withParsedBody($body);
    }

    private function data(\Psr\Http\Message\ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['data'];
    }

    public function test_only_verified_customer_identity_controls_the_list(): void
    {
        self::assertSame(401, $this->controller->show($this->request('GET', null), new Response())->getStatusCode());
        self::assertSame(401, $this->controller->show($this->request('GET', 'unknown'), new Response())->getStatusCode());
        $this->controller->add($this->request('POST', 'alice', ['id_customer' => 2, 'id_product' => 5]), new Response());
        $alice = $this->data($this->controller->show($this->request('GET'), new Response()))['wishlist'];
        $bob = $this->data($this->controller->show($this->request('GET', 'bob'), new Response()))['wishlist'];
        self::assertCount(1, $alice['products']);
        self::assertTrue($alice['products'][0]['available']);
        self::assertSame([], $bob['products']);
        self::assertNotSame($alice['share_token'], $bob['share_token']);
        $this->controller->remove($this->request('DELETE', 'bob', ['id_customer' => 1, 'id_product' => 5]), new Response());
        self::assertCount(1, $this->data($this->controller->show($this->request('GET'), new Response()))['wishlist']['products']);
    }

    public function test_products_are_idempotent_validated_and_removable_after_disappearing(): void
    {
        self::assertSame(404, $this->controller->add($this->request('POST', 'alice', ['id_product' => 999]), new Response())->getStatusCode());
        self::assertSame(404, $this->controller->add($this->request('POST', 'alice', ['id_product' => 6]), new Response())->getStatusCode());
        self::assertSame(404, $this->controller->add($this->request('POST', 'alice', ['id_product' => 5, 'id_product_attribute' => 99]), new Response())->getStatusCode());
        self::assertSame(422, $this->controller->add($this->request('POST', 'alice', ['id_product' => -1]), new Response())->getStatusCode());
        $body = ['id_product' => 5, 'id_product_attribute' => 12];
        $this->controller->add($this->request('POST', 'alice', $body), new Response());
        $this->controller->add($this->request('POST', 'alice', $body), new Response());
        self::assertFalse($this->data($this->controller->show($this->request('GET'), new Response()))['wishlist']['products'][0]['available']);
        $this->db->getConnection()->table('product_attribute')->where('id_product_attribute', 12)->delete();
        $list = $this->data($this->controller->show($this->request('GET'), new Response()))['wishlist'];
        self::assertCount(1, $list['products']);
        self::assertFalse($list['products'][0]['available']);
        $removed = $this->data($this->controller->remove($this->request('DELETE', 'alice', $body), new Response()))['wishlist'];
        self::assertSame([], $removed['products']);
    }

    public function test_existing_default_list_is_imported_once_per_customer(): void
    {
        $db = $this->db->getConnection();
        $db->statement('CREATE TABLE wishlist (id_wishlist INTEGER PRIMARY KEY, id_customer INTEGER, name TEXT, "default" INTEGER)');
        $db->statement('CREATE TABLE wishlist_product (id_wishlist INTEGER, id_product INTEGER, id_product_attribute INTEGER)');
        $db->table('wishlist')->insert([
            ['id_wishlist' => 1, 'id_customer' => 1, 'name' => 'Altra', 'default' => 0],
            ['id_wishlist' => 2, 'id_customer' => 1, 'name' => 'Preferita', 'default' => 1],
            ['id_wishlist' => 3, 'id_customer' => 2, 'name' => 'Privata', 'default' => 1],
        ]);
        $db->table('wishlist_product')->insert([
            ['id_wishlist' => 2, 'id_product' => 5, 'id_product_attribute' => 0],
            ['id_wishlist' => 2, 'id_product' => 5, 'id_product_attribute' => 0],
            ['id_wishlist' => 3, 'id_product' => 6, 'id_product_attribute' => 0],
        ]);
        $list = $this->data($this->controller->show($this->request('GET'), new Response()))['wishlist'];
        self::assertSame('Preferita', $list['name']);
        self::assertCount(1, $list['products']);
        self::assertSame(5, $list['products'][0]['id_product']);
        $this->controller->remove($this->request('DELETE', 'alice', ['id_product' => 5]), new Response());
        self::assertSame([], $this->data($this->controller->show($this->request('GET'), new Response()))['wishlist']['products']);
    }

    public function test_first_operation_can_remove_an_imported_item(): void
    {
        $db = $this->db->getConnection();
        $db->statement('CREATE TABLE wishlist (id_wishlist INTEGER PRIMARY KEY, id_customer INTEGER, name TEXT, "default" INTEGER)');
        $db->statement('CREATE TABLE wishlist_product (id_wishlist INTEGER, id_product INTEGER, id_product_attribute INTEGER)');
        $db->table('wishlist')->insert(['id_wishlist' => 1, 'id_customer' => 1, 'name' => 'Vecchia', 'default' => 1]);
        $db->table('wishlist_product')->insert(['id_wishlist' => 1, 'id_product' => 5, 'id_product_attribute' => 0]);
        $list = $this->data($this->controller->remove($this->request('DELETE', 'alice', ['id_product' => 5]), new Response()))['wishlist'];
        self::assertSame([], $list['products']);
    }

    public function test_out_of_stock_policy_and_missing_catalog_product(): void
    {
        $this->controller->add($this->request('POST', 'alice', ['id_product' => 5, 'id_product_attribute' => 12]), new Response());
        $db = $this->db->getConnection();
        $db->table('stock_available')->where('id_product_attribute', 12)->update(['out_of_stock' => 2]);
        $db->table('configuration')->insert(['id_configuration' => 1, 'name' => 'PS_ORDER_OUT_OF_STOCK', 'value' => '1']);
        self::assertTrue($this->data($this->controller->show($this->request('GET'), new Response()))['wishlist']['products'][0]['available']);
        $db->table('product')->where('id_product', 5)->delete();
        self::assertFalse($this->data($this->controller->show($this->request('GET'), new Response()))['wishlist']['products'][0]['available']);
    }

    public function test_shared_list_excludes_owner_and_secret_and_supports_name(): void
    {
        $renamed = $this->controller->rename($this->request('PUT', 'alice', ['name' => ' Compleanno ']), new Response());
        $token = $this->data($renamed)['wishlist']['share_token'];
        $shortToken = $this->data($renamed)['wishlist']['short_token'];
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/', $shortToken);
        self::assertSame(422, $this->controller->rename($this->request('PUT', 'alice', ['name' => ' ']), new Response())->getStatusCode());
        $this->controller->add($this->request('POST', 'alice', ['id_product' => 5]), new Response());
        $shared = $this->controller->shared($this->request('GET', null), new Response(), ['token' => $token]);
        self::assertSame('no-store', $shared->getHeaderLine('Cache-Control'));
        $list = $this->data($shared)['wishlist'];
        self::assertSame('Compleanno', $list['name']);
        self::assertArrayNotHasKey('share_token', $list);
        self::assertArrayNotHasKey('customer_id', $list);
        self::assertCount(1, $list['products']);
        $shortList = $this->data($this->controller->shared($this->request('GET', null), new Response(), ['token' => $shortToken]))['wishlist'];
        self::assertSame($list, $shortList);
        self::assertArrayNotHasKey('short_token', $shortList);
        self::assertSame(404, $this->controller->shared($this->request('GET', null), new Response(), ['token' => str_repeat('0', 64)])->getStatusCode());
        self::assertSame(404, $this->controller->shared($this->request('GET', null), new Response(), ['token' => 'invalid'])->getStatusCode());
    }

    public function test_ambiguous_short_token_never_exposes_a_different_list(): void
    {
        $alice = $this->data($this->controller->show($this->request('GET'), new Response()))['wishlist'];
        $this->controller->show($this->request('GET', 'bob'), new Response());
        $this->db->getConnection()->table('dz_wishlist')->where('customer_id', 2)->update([
            'share_token' => substr($alice['share_token'], 0, 32) . str_repeat('f', 32),
        ]);
        $after = $this->data($this->controller->show($this->request('GET'), new Response()))['wishlist'];
        self::assertArrayNotHasKey('short_token', $after);
        self::assertSame(404, $this->controller->shared($this->request('GET', null), new Response(), ['token' => $alice['short_token']])->getStatusCode());
        self::assertSame(200, $this->controller->shared($this->request('GET', null), new Response(), ['token' => $alice['share_token']])->getStatusCode());
    }
}
