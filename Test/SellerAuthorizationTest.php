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
            ->willReturn([]);

        $controller = $this->createController($repository);
        $request = $this->createMock(ServerRequestInterface::class);

        $result = $controller->sellerProducts($request, null, ['sellerid' => 5]);

        $this->assertSame(200, $result->getStatusCode());
    }
}
