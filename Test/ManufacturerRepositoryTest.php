<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\ConnectionResolverInterface;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Repositories\ManufacturerRepository;
use PS\Webservice\Domain\Entities\ManufactureEntity;
use PS\Webservice\Domain\Models\PS\Suppliers\Supplier;

final class ManufacturerRepositoryTest extends TestCase
{
    private Manager $database;
    private ?Manager $previousGlobalDatabase = null;
    private ?ConnectionResolverInterface $previousResolver = null;

    protected function setUp(): void
    {
        $instance = new ReflectionProperty(Manager::class, 'instance');
        $this->previousGlobalDatabase = $instance->getValue();
        $this->database = new Manager();
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->setAsGlobal();
        $this->previousResolver = Model::getConnectionResolver();
        $this->database->bootEloquent();
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
        if ($this->previousResolver) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
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

    public function test_supplier_address_accepts_province_name_and_code_only_for_the_country(): void
    {
        $schema = $this->database->getConnection()->getSchemaBuilder();
        $schema->create('state', function ($table): void {
            $table->integer('id_state')->primary();
            $table->integer('id_country');
            $table->string('name');
            $table->string('iso_code');
        });
        $schema->create('address', function ($table): void {
            $table->increments('id_address');
            $table->integer('id_supplier');
            $table->integer('id_country');
            $table->integer('id_state');
            foreach (['address1', 'city', 'postcode', 'dni', 'phone', 'vat_number', 'iban', 'alias', 'lastname', 'firstname', 'date_add', 'date_upd', 'company', 'other', 'address2'] as $column) {
                $table->string($column)->nullable();
            }
        });
        $this->database->table('state')->insert(['id_state' => 7, 'id_country' => 10, 'name' => 'Padova', 'iso_code' => 'PD']);
        $supplier = new Supplier();
        $supplier->id_supplier = 1;
        $entity = (new ReflectionClass(ManufactureEntity::class))->newInstanceWithoutConstructor();
        $data = new ReflectionProperty(ManufactureEntity::class, 'data');
        $method = new ReflectionMethod(ManufacturerRepository::class, 'createOrUpdateSupplierAddress');
        $repository = new ManufacturerRepository($this->database);

        foreach (['PD', 'pd', 'Padova'] as $state) {
            $data->setValue($entity, ['city' => 'Padova', 'state' => $state, 'id_country' => 10]);
            $method->invoke($repository, $supplier, $entity);
            self::assertSame(7, $this->database->table('address')->value('id_state'));
        }

        $data->setValue($entity, ['city' => 'Padova', 'state' => 'PD', 'id_country' => 99]);
        $this->expectException(InvalidArgumentException::class);
        $method->invoke($repository, $supplier, $entity);
    }
}
