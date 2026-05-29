<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Http\Controller\PetProfessionalServiceController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PetProfessionalServiceControllerTest extends TestCase
{
    private static bool $databaseBootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$databaseBootstrapped) {
            $capsule = new Capsule();
            $capsule->addConnection([
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
            $capsule->setAsGlobal();
            $capsule->bootEloquent();

            Capsule::schema()->create('pet_professional_services', static function (Blueprint $table): void {
                $table->increments('id');
                $table->string('service_type')->nullable();
                $table->timestamps();
            });

            self::$databaseBootstrapped = true;
        }

        Capsule::table('pet_professional_services')->truncate();
    }

    public function test_categories_returns_unique_sorted_non_empty_service_types(): void
    {
        Capsule::table('pet_professional_services')->insert([
            [
                'service_type' => 'Toelettatura',
                'created_at' => '2026-05-29 00:00:00',
                'updated_at' => '2026-05-29 00:00:00',
            ],
            [
                'service_type' => ' Educatore ',
                'created_at' => '2026-05-29 00:00:00',
                'updated_at' => '2026-05-29 00:00:00',
            ],
            [
                'service_type' => 'Toelettatura',
                'created_at' => '2026-05-29 00:00:00',
                'updated_at' => '2026-05-29 00:00:00',
            ],
            [
                'service_type' => '',
                'created_at' => '2026-05-29 00:00:00',
                'updated_at' => '2026-05-29 00:00:00',
            ],
            [
                'service_type' => null,
                'created_at' => '2026-05-29 00:00:00',
                'updated_at' => '2026-05-29 00:00:00',
            ],
        ]);

        $controller = new PetProfessionalServiceController();
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->categories($request, $response);

        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame([
            'success' => true,
            'data' => [
                'categories' => [
                    'Educatore',
                    'Toelettatura',
                ],
            ],
        ], json_decode((string) $result->getBody(), true));
    }
}
