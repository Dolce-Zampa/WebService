<?php
declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Commands\AddProductBundleCommand;
use PS\Webservice\Domain\Entities\ProductEntity;
use PS\Webservice\Domain\Models\PS\Products\ProductBundle;
use PS\Webservice\Service\PS\Product;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ProductBundleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $app->instance('cache', new Repository(new ArrayStore()));
        $app->instance('log', new Psr\Log\NullLogger());

        $schema = DB::schema();
        $schema->dropIfExists('product_bundles');
        $schema->create('product_bundles', function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('parent_product_id');
            $table->unsignedInteger('bundled_product_id');
            $table->decimal('reduction', 10, 2)->default(0);
            $table->string('reduction_type');
            $table->boolean('active_reduction')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['parent_product_id', 'bundled_product_id']);
        });
    }

    protected function tearDown(): void
    {
        DB::schema()->dropIfExists('product_bundles');
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_product_entity_reads_bundles_from_the_database(): void
    {
        ProductBundle::query()->create([
            'parent_product_id' => 244,
            'bundled_product_id' => 253,
            'reduction' => 0,
            'reduction_type' => 'amount',
            'active_reduction' => false,
            'position' => 0,
        ]);
        $service = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->onlyMethods(['getProductById'])->getMock();
        $service
            ->expects($this->once())
            ->method('getProductById')
            ->with(253)
            ->willReturn(ProductEntity::fromSnapshot(['id' => 253, 'price' => 22.0], $service, true));
        $product = ProductEntity::fromSnapshot(['id' => 244], $service);

        (new ReflectionMethod(ProductEntity::class, 'buildBundles'))->invoke($product);

        $bundle = $product->toArray()['bundles'][0];
        $this->assertSame(253, $bundle['id']);
        $this->assertSame(0.0, $bundle['bundle_reduction']);
        $this->assertSame('amount', $bundle['bundle_reduction_type']);
        $this->assertFalse($bundle['bundle_active_reduction']);
    }

    public function test_command_creates_and_updates_a_bundle_link(): void
    {
        $tester = new CommandTester(new AddProductBundleCommand());
        $this->assertSame(Command::SUCCESS, $tester->execute([
            'parent-product-id' => '244',
            'bundled-product-id' => '253',
            '--reduction' => '0',
            '--reduction-type' => 'amount',
            '--active-reduction' => 'false',
        ]));
        $this->assertStringContainsString('Creata associazione 244 → 253', $tester->getDisplay());

        $this->assertSame(Command::SUCCESS, $tester->execute([
            'parent-product-id' => '244',
            'bundled-product-id' => '253',
            '--reduction' => '15',
            '--reduction-type' => 'percentage',
            '--active-reduction' => 'true',
            '--position' => '2',
        ]));

        $bundle = ProductBundle::query()->sole();
        $this->assertSame(15.0, $bundle->reduction);
        $this->assertSame('percentage', $bundle->reduction_type);
        $this->assertTrue($bundle->active_reduction);
        $this->assertSame(2, $bundle->position);
        $this->assertSame(1, ProductBundle::query()->count());
    }

    public function test_command_rejects_invalid_bundle_data(): void
    {
        $tester = new CommandTester(new AddProductBundleCommand());
        $this->assertSame(Command::INVALID, $tester->execute([
            'parent-product-id' => '244',
            'bundled-product-id' => '244',
        ]));
        $this->assertSame(0, ProductBundle::query()->count());
    }
}
