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
                $table->string('address')->nullable();
                $table->string('service_type')->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
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
                'service_type' => 'toilettatore',
                'created_at' => '2026-05-29 00:00:00',
                'updated_at' => '2026-05-29 00:00:00',
            ],
            [
                'service_type' => ' allevamento ',
                'created_at' => '2026-05-29 00:00:00',
                'updated_at' => '2026-05-29 00:00:00',
            ],
            [
                'service_type' => 'toilettatore',
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
                    'allevamento',
                    'toilettatore',
                ],
            ],
        ], json_decode((string) $result->getBody(), true));
    }

    public function test_save_rejects_invalid_service_type(): void
    {
        $controller = new PetProfessionalServiceController();
        $request = $this->createMock(ServerRequestInterface::class);
        $request
            ->method('getParsedBody')
            ->willReturn([
                'first_name' => 'Mario',
                'last_name' => 'Rossi',
                'address' => 'Via Roma',
                'service_type' => 'educatore',
            ]);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->save($request, $response);

        $this->assertSame(422, $result->getStatusCode());
        $this->assertSame([
            'success' => true,
            'data' => [
                'message' => 'service_type non valido. Valori consentiti: pet-sitting, toilettatore, allevamento.',
            ],
        ], json_decode((string) $result->getBody(), true));
    }

    public function test_search_filters_by_latitude_and_longitude_range(): void
    {
        Capsule::table('pet_professional_services')->insert([
            [
                'address' => 'Milano',
                'service_type' => 'pet-sitting',
                'latitude' => 45.4642000,
                'longitude' => 9.1900000,
                'created_at' => '2026-06-04 00:00:00',
                'updated_at' => '2026-06-04 00:00:00',
            ],
            [
                'address' => 'Roma',
                'service_type' => 'toilettatore',
                'latitude' => 41.9028000,
                'longitude' => 12.4964000,
                'created_at' => '2026-06-04 00:00:00',
                'updated_at' => '2026-06-04 00:00:00',
            ],
        ]);

        $controller = new PetProfessionalServiceController();
        $request = $this->createMock(ServerRequestInterface::class);
        $request
            ->method('getQueryParams')
            ->willReturn([
                'lat_min' => '45.0',
                'lat_max' => '46.0',
                'lng_min' => '9.0',
                'lng_max' => '10.0',
            ]);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->search($request, $response);
        $payload = json_decode((string) $result->getBody(), true);

        $this->assertSame(200, $result->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame(1, $payload['data']['total']);
        $this->assertSame('Milano', $payload['data']['items'][0]['address']);
    }

    public function test_search_rejects_invalid_latitude_range(): void
    {
        $controller = new PetProfessionalServiceController();
        $request = $this->createMock(ServerRequestInterface::class);
        $request
            ->method('getQueryParams')
            ->willReturn([
                'lat_min' => '91',
            ]);
        $response = $this->createMock(ResponseInterface::class);

        $result = $controller->search($request, $response);

        $this->assertSame(422, $result->getStatusCode());
        $this->assertSame([
            'success' => false,
            'data' => [
                'message' => 'Range latitude non valido. Valori consentiti: da -90 a 90.',
            ],
        ], json_decode((string) $result->getBody(), true));
    }
}
