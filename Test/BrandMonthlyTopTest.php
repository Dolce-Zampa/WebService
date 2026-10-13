<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\HttpServiceInterface;
use PS\Webservice\Service\PS\Brand;

final class BrandMonthlyTopTest extends TestCase
{
    private DB $db;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-13 12:00:00');
        $this->db = new DB();
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->db->setAsGlobal();
        $this->db->bootEloquent();
        $schema = $this->db->schema();

        $schema->create('orders', function ($table): void {
            $table->unsignedInteger('id_order')->primary();
            $table->unsignedInteger('current_state');
            $table->dateTime('date_add');
        });
        $schema->create('order_state', function ($table): void {
            $table->unsignedInteger('id_order_state')->primary();
            $table->boolean('paid');
        });
        $schema->create('order_detail', function ($table): void {
            $table->increments('id_order_detail');
            $table->unsignedInteger('id_order');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('product_quantity');
            $table->decimal('total_price_tax_incl', 20, 6);
        });
        $schema->create('product', function ($table): void {
            $table->unsignedInteger('id_product')->primary();
            $table->unsignedInteger('id_manufacturer');
        });
        $schema->create('manufacturer', function ($table): void {
            $table->unsignedInteger('id_manufacturer')->primary();
            $table->string('name');
            $table->string('link_rewrite')->nullable();
            $table->boolean('active');
        });
        $schema->create('manufacturer_details', function ($table): void {
            $table->unsignedInteger('id_manufacturer')->primary();
            $table->string('avatar')->nullable();
        });

        DB::table('order_state')->insert([
            ['id_order_state' => 2, 'paid' => true],
            ['id_order_state' => 3, 'paid' => false],
        ]);
        DB::table('manufacturer')->insert([
            ['id_manufacturer' => 1, 'name' => 'Primo', 'link_rewrite' => 'primo', 'active' => true],
            ['id_manufacturer' => 2, 'name' => 'Secondo', 'link_rewrite' => 'secondo', 'active' => true],
            ['id_manufacturer' => 3, 'name' => 'Inattivo', 'link_rewrite' => 'inattivo', 'active' => false],
        ]);
        DB::table('manufacturer_details')->insert([
            ['id_manufacturer' => 1, 'avatar' => 'https://example.test/primo.jpg'],
            ['id_manufacturer' => 2, 'avatar' => 'https://example.test/secondo.jpg'],
        ]);
        DB::table('product')->insert([
            ['id_product' => 101, 'id_manufacturer' => 1],
            ['id_product' => 102, 'id_manufacturer' => 2],
            ['id_product' => 103, 'id_manufacturer' => 3],
        ]);
        DB::table('orders')->insert([
            ['id_order' => 501, 'current_state' => 2, 'date_add' => '2026-10-10 10:00:00'],
            ['id_order' => 502, 'current_state' => 2, 'date_add' => '2026-09-30 10:00:00'],
            ['id_order' => 503, 'current_state' => 3, 'date_add' => '2026-10-11 10:00:00'],
        ]);
        DB::table('order_detail')->insert([
            ['id_order' => 501, 'product_id' => 101, 'product_quantity' => 4, 'total_price_tax_incl' => '50.00'],
            ['id_order' => 501, 'product_id' => 102, 'product_quantity' => 2, 'total_price_tax_incl' => '75.00'],
            ['id_order' => 501, 'product_id' => 103, 'product_quantity' => 9, 'total_price_tax_incl' => '999.00'],
            ['id_order' => 502, 'product_id' => 101, 'product_quantity' => 5, 'total_price_tax_incl' => '999.00'],
            ['id_order' => 503, 'product_id' => 101, 'product_quantity' => 5, 'total_price_tax_incl' => '999.00'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_returns_only_active_artisans_ranked_by_current_month_paid_sales(): void
    {
        $httpService = $this->createMock(HttpServiceInterface::class);

        $artisans = (new Brand($httpService))->monthlyTop(4);

        self::assertSame([2, 1], array_column($artisans, 'id'));
        self::assertSame('secondo', $artisans[0]['slug']);
        self::assertSame('https://example.test/secondo.jpg', $artisans[0]['image']);
    }
}
