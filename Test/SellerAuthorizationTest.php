<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Http\Controller\Seller\SellerController;
use PS\Webservice\Repositories\ManufacturerRepository;
use PS\Webservice\Service\Auth\AuthService;
use PS\Webservice\Service\MailjetService;
use PS\Webservice\Service\PS\Mailer;
use PS\Webservice\Service\PS\PrestashopService;
use PS\Webservice\Service\PS\Product;
use PS\Webservice\Service\Promotions\PromotionService;
use Psr\Http\Message\ServerRequestInterface;

final class SellerAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication([
            'aws-cognito-client' => new class {
                public function decodeAccessToken(string $accessToken): array
                {
                    return ['sub' => 'seller-sub'];
                }
            },
            'log' => $this->createMock(\Psr\Log\LoggerInterface::class),
        ]);

        foreach (['manufacturer', 'manufacturer_details', 'address'] as $table) {
            if (Manager::schema()->hasTable($table)) {
                Manager::schema()->drop($table);
            }
        }

        Manager::schema()->create('manufacturer', function ($table): void {
            $table->integer('id_manufacturer')->primary();
            $table->string('sub')->nullable();
            $table->string('name')->nullable();
        });

        Manager::schema()->create('manufacturer_details', function ($table): void {
            $table->integer('id_manufacturer')->primary();
            $table->string('avatar')->nullable();
        });

        Manager::schema()->create('address', function ($table): void {
            $table->integer('id_address')->primary();
            $table->integer('id_manufacturer')->nullable();
        });

        Manager::table('manufacturer')->insert([
            'id_manufacturer' => 5,
            'sub' => 'seller-sub',
            'name' => 'Seller',
        ]);
    }

    private function createController(ManufacturerRepository $repository): SellerController
    {
        $authService = $this->createMock(AuthService::class);
        $authService->method('check')->willReturn('valid-token');

        return new SellerController(
            $authService,
            $this->createMock(PrestashopService::class),
            $this->createMock(Mailer::class),
            $repository,
            $this->createMock(Product::class),
            $this->createMock(MailjetService::class),
            $this->createMock(PromotionService::class)
        );
    }

    public function test_seller_products_returns_403_for_different_seller(): void
    {
        $repository = $this->getMockBuilder(ManufacturerRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getProductAddToCart'])
            ->getMock();
        $repository->expects($this->never())->method('getProductAddToCart');

        $controller = $this->createController($repository);
        $request = $this->createMock(ServerRequestInterface::class);

        $result = $controller->sellerProducts($request, null, ['sellerid' => 9]);

        $this->assertSame(403, $result->getStatusCode());
    }

    public function test_seller_products_returns_200_for_authenticated_seller(): void
    {
        $repository = $this->getMockBuilder(ManufacturerRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getProductAddToCart'])
            ->getMock();
        $repository->expects($this->once())
            ->method('getProductAddToCart')
            ->with(5)
            ->willReturn(collect());

        $controller = $this->createController($repository);
        $request = $this->createMock(ServerRequestInterface::class);

        $result = $controller->sellerProducts($request, null, ['sellerid' => 5]);

        $this->assertSame(200, $result->getStatusCode());
    }
    public function test_foreign_product_cannot_be_updated(): void
    {
        $product = $this->createMock(\PS\Webservice\Domain\Entities\ProductEntity::class);
        $product->method('toArray')->willReturn(['id' => 42, 'id_manufacturer' => 9]);
        $productService = $this->createMock(Product::class);
        $productService->expects($this->once())->method('getProductById')->with(42)->willReturn($product);
        $productService->expects($this->never())->method('updateProduct');
        $auth = $this->createMock(AuthService::class);
        $auth->method('check')->willReturn('valid-token');
        $controller = new SellerController($auth, $this->createMock(PrestashopService::class), $this->createMock(Mailer::class),
            $this->createMock(ManufacturerRepository::class), $productService, $this->createMock(MailjetService::class), $this->createMock(PromotionService::class));
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['name' => 'Changed', 'id_manufacturer' => 5]);
        self::assertSame(403, $controller->updateProduct($request, null, ['id_product' => 42])->getStatusCode());
    }

}
