<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Repositories\ManufacturerRepository;

final class ManufacturerRepositoryTest extends TestCase
{
    private Manager $database;
    private ?Manager $previousGlobalDatabase = null;

    protected function setUp(): void
    {
        $instance = new ReflectionProperty(Manager::class, 'instance');
        $this->previousGlobalDatabase = $instance->getValue();
        $this->database = new Manager();
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->setAsGlobal();
        $schema = $this->database->getConnection()->getSchemaBuilder();

        $schema->create('product', function ($table): void {
            $table->unsignedInteger('id_product')->primary();
            $table->boolean('active');
        });
        $schema->create('v_manufacturer_cart_products_stats', function ($table): void {
            $table->unsignedInteger('id_product');
            $table->unsignedInteger('id_manufacturer');
            $table->string('reference')->nullable();
        });
    }

    protected function tearDown(): void
    {
        $this->previousGlobalDatabase?->setAsGlobal();
        parent::tearDown();
    }

    public function test_product_stats_exclude_inactive_products(): void
    {
        $this->database->table('product')->insert([
            ['id_product' => 250, 'active' => 1],
            ['id_product' => 251, 'active' => 0],
        ]);
        $this->database->table('v_manufacturer_cart_products_stats')->insert([
            ['id_product' => 250, 'id_manufacturer' => 9, 'reference' => 'ACTIVE'],
            ['id_product' => 251, 'id_manufacturer' => 9, 'reference' => 'INACTIVE'],
        ]);

        $products = (new ManufacturerRepository($this->database))->getProductAddToCart(9);

        self::assertSame([250], $products->pluck('id_product')->all());
    }
}
