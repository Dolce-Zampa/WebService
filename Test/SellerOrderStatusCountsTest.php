<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Http\Controller\Seller\SellerController;


final class SellerOrderStatusCountsTest extends TestCase
{
    public function testTrackingIsRequestedOnlyForOrdersWhollyBelongingToTheSeller(): void
    {
        $db = new Manager();
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'ps_']);
        $connection = $db->getConnection();
        $schema = $connection->getSchemaBuilder();
        $schema->create('order_detail', function ($table): void { $table->integer('id_order'); $table->integer('product_id'); });
        $schema->create('product', function ($table): void { $table->integer('id_product'); $table->integer('id_manufacturer'); });
        $connection->table('product')->insert([
            ['id_product' => 11, 'id_manufacturer' => 7],
            ['id_product' => 12, 'id_manufacturer' => 8],
        ]);
        $connection->table('order_detail')->insert([
            ['id_order' => 1, 'product_id' => 11], ['id_order' => 1, 'product_id' => 11],
            ['id_order' => 2, 'product_id' => 11], ['id_order' => 2, 'product_id' => 12],
            ['id_order' => 3, 'product_id' => 12],
            ['id_order' => 4, 'product_id' => 11], ['id_order' => 4, 'product_id' => 999],
        ]);
        $controller = (new ReflectionClass(SellerController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(SellerController::class, 'singleSellerOrderIds');
        self::assertSame([1], $method->invoke($controller, $connection, [1, 2, 3, 4], 7));
        self::assertSame([], $method->invoke($controller, $connection, [], 7));
    }

    public function testCountsDistinctSellerOrdersWithPrestashopPrefix(): void
    {
        $db = new Manager();
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'ps_']);
        $connection = $db->getConnection();
        $schema = $connection->getSchemaBuilder();
        $schema->create('orders', function ($table): void {
            $table->integer('id_order'); $table->integer('id_lang'); $table->integer('current_state');
        });
        $schema->create('order_detail', function ($table): void {
            $table->integer('id_order'); $table->integer('product_id');
        });
        $schema->create('product', function ($table): void {
            $table->integer('id_product'); $table->integer('id_manufacturer');
        });
        $schema->create('order_state_lang', function ($table): void {
            $table->integer('id_order_state'); $table->integer('id_lang'); $table->string('name');
        });
        $connection->table('orders')->insert([
            ['id_order' => 1, 'id_lang' => 1, 'current_state' => 2],
            ['id_order' => 2, 'id_lang' => 1, 'current_state' => 3],
        ]);
        $connection->table('product')->insert([
            ['id_product' => 11, 'id_manufacturer' => 7],
            ['id_product' => 12, 'id_manufacturer' => 8],
        ]);
        $connection->table('order_detail')->insert([
            ['id_order' => 1, 'product_id' => 11],
            ['id_order' => 1, 'product_id' => 11],
            ['id_order' => 2, 'product_id' => 12],
        ]);
        $connection->table('order_state_lang')->insert([
            ['id_order_state' => 2, 'id_lang' => 1, 'name' => 'In preparazione'],
            ['id_order_state' => 3, 'id_lang' => 1, 'name' => 'Consegnato'],
        ]);
        $base = $connection->table('orders as o')
            ->join('order_detail as d', 'd.id_order', '=', 'o.id_order')
            ->join('product as p', 'p.id_product', '=', 'd.product_id')
            ->where('p.id_manufacturer', 7);
        $controller = (new ReflectionClass(SellerController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(SellerController::class, 'sellerOrderStatusCounts');
        $rows = $method->invoke($controller, $connection, $base);
        self::assertCount(1, $rows);
        self::assertSame(2, $rows[0]->id);
        self::assertSame('In preparazione', $rows[0]->name);
        self::assertSame(1, $rows[0]->count);
    }
}
