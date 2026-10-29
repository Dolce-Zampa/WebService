<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\PS\ProductPromotionDates;

final class ProductPromotionDatesTest extends TestCase
{
    protected function setUp(): void
    {
        $database = new DB();
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $database->setAsGlobal();
        $database->bootEloquent();
        $database->getConnection()->getSchemaBuilder()->create('specific_price', function ($table): void {
            $table->increments('id_specific_price');
            $table->integer('id_product');
            $table->integer('id_product_attribute')->default(0);
            $table->integer('id_cart')->default(0);
            $table->integer('id_customer')->default(0);
            $table->integer('from_quantity')->default(1);
            $table->decimal('reduction', 10, 2)->default(0);
            $table->decimal('price', 10, 2)->default(-1);
            $table->dateTime('from');
            $table->dateTime('to');
        });
    }

    public function test_returns_only_active_promotions_started_today_for_the_product(): void
    {
        $now = new DateTimeImmutable('2026-11-20 12:00:00', new DateTimeZone('Europe/Rome'));
        $row = ['id_product' => 7, 'from' => '2026-11-20 10:00:00', 'to' => '2026-11-22 23:59:59', 'reduction' => 0.2];
        foreach ([
            $row,
            array_merge($row, ['id_product_attribute' => 9, 'from' => '2026-11-20 11:00:00']),
            array_merge($row, ['id_product_attribute' => 10, 'from' => '2026-11-19 10:00:00']),
            array_merge($row, ['id_product_attribute' => 11, 'from' => '2026-11-20 13:00:00']),
            array_merge($row, ['id_product_attribute' => 12, 'to' => '2026-11-20 11:00:00']),
            array_merge($row, ['id_product_attribute' => 13, 'id_customer' => 1]),
            array_merge($row, ['id_product_attribute' => 14, 'id_cart' => 1]),
            array_merge($row, ['id_product_attribute' => 15, 'from_quantity' => 2]),
            array_merge($row, ['id_product_attribute' => 16, 'reduction' => 0]),
            array_merge($row, ['id_product' => 8, 'id_product_attribute' => 17]),
        ] as $record) {
            DB::table('specific_price')->insert($record);
        }

        $promotions = (new ProductPromotionDates())->forProduct(7, $now);
        $this->assertEqualsCanonicalizing([0, 9], array_keys($promotions));
        $this->assertSame('2026-11-20T10:00:00+01:00', $promotions[0]['start']);
        $this->assertSame('2026-11-22T23:59:59+01:00', $promotions[9]['end']);
        $this->assertSame([], (new ProductPromotionDates())->forProduct(7, $now->modify('+1 day')));
    }
    public function test_product_detail_includes_dates_for_the_selected_combination(): void
    {
        $container = new Illuminate\Container\Container();
        Illuminate\Support\Facades\Facade::clearResolvedInstances();
        Illuminate\Support\Facades\Facade::setFacadeApplication($container);
        $container->instance('cache', new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
        $container->instance('log', new Psr\Log\NullLogger());
        $timezone = new DateTimeZone((string) env('APP_TIMEZONE', 'Europe/Rome'));
        $now = new DateTimeImmutable('now', $timezone);
        DB::table('specific_price')->insert([
            'id_product' => 7, 'id_product_attribute' => 9, 'reduction' => 0.2,
            'from' => $now->setTime(0, 0)->format('Y-m-d H:i:s'),
            'to' => $now->modify('+1 day')->format('Y-m-d H:i:s'),
        ]);
        $http = $this->createMock(PS\Webservice\Service\HttpServiceInterface::class);
        $http->method('getConfig')->willReturn(new PS\Webservice\Domain\Object\WebserviceConfig('https://shop.test/api'));
        $service = $this->getMockBuilder(PS\Webservice\Service\PS\Product::class)
            ->setConstructorArgs([$http])->onlyMethods(['getProductById'])->getMock();
        $service->method('getProductById')->willReturn(PS\Webservice\Domain\Entities\ProductEntity::fromSnapshot([
            'id' => 7, 'price' => 10, 'associations' => ['combinations' => [['id' => 9]]],
        ], $service, true));
        $data = $service->getCompleteProductById(7)->toArray();
        $this->assertNull($data['promotion']);
        $this->assertSame($now->setTime(0, 0)->format(DateTimeInterface::ATOM), $data['associations']['combinations'][0]['promotion']['start']);
        $this->assertSame($data['associations']['combinations'][0]['promotion']['end'], $data['associations']['combinations'][0]['promotion_end']);
        Illuminate\Support\Facades\Facade::clearResolvedInstances();
        Illuminate\Support\Facades\Facade::setFacadeApplication(null);
    }

}
