<?php

declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Domain\Models\PetProfessionalService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PetProfessionalServiceController extends Controller
{
    private const ALLOWED_SERVICE_TYPES = ['pet-sitting', 'toilettatore', 'allevamento'];

    public function categories(Request $request, Response $response): Response
    {
        try {
            $categories = PetProfessionalService::query()
                ->selectRaw('TRIM(service_type) as service_type')
                ->whereNotNull('service_type')
                ->whereRaw("TRIM(service_type) <> ''")
                ->distinct()
                ->orderBy('service_type')
                ->pluck('service_type')
                ->values()
                ->all();

            return response([
                'categories' => $categories,
            ]);
        } catch (\Exception $e) {
            return $this->internalError($e);
        }
    }

    public function index(Request $request, Response $response): Response
    {
        try {
            $queryParams = $request->getQueryParams();
            $page = max(1, (int) ($queryParams['page'] ?? 1));
            $limit = min(100, max(1, (int) ($queryParams['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;

            $items = PetProfessionalService::query()
                ->orderBy('id', 'desc')
                ->offset($offset)
                ->limit($limit)
                ->get();
            $total = PetProfessionalService::query()->count();

            return response([
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'items' => $items->toArray(),
            ]);
        } catch (\Exception $e) {
            return $this->internalError($e);
        }
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        try {
            $item = PetProfessionalService::query()->find((int) ($args['id'] ?? 0));

            if (!$item) {
                return response(['message' => 'Servizio non trovato.'], 404);
            }

            return response($item->toArray());
        } catch (\Exception $e) {
            return $this->internalError($e);
        }
    }

    public function search(Request $request, Response $response): Response
    {
        try {
            $queryParams = $request->getQueryParams();
            $serviceType = trim((string) ($queryParams['service_type'] ?? ''));
            $address = trim((string) ($queryParams['address'] ?? ''));
            $latitudeMin = $this->parseFloatQueryParam($queryParams, 'lat_min', ['latitude_min']);
            $latitudeMax = $this->parseFloatQueryParam($queryParams, 'lat_max', ['latitude_max']);
            $longitudeMin = $this->parseFloatQueryParam($queryParams, 'lng_min', ['lon_min', 'long_min', 'longitude_min']);
            $longitudeMax = $this->parseFloatQueryParam($queryParams, 'lng_max', ['lon_max', 'long_max', 'longitude_max']);
            $page = max(1, (int) ($queryParams['page'] ?? 1));
            $limit = min(100, max(1, (int) ($queryParams['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;

            foreach ([$latitudeMin, $latitudeMax, $longitudeMin, $longitudeMax] as $coordinateParam) {
                if (isset($coordinateParam['error'])) {
                    return response(['message' => $coordinateParam['error']], 422);
                }
            }

            $hasCoordinateFilter = $latitudeMin['provided']
                || $latitudeMax['provided']
                || $longitudeMin['provided']
                || $longitudeMax['provided'];

            if ($serviceType === '' && $address === '' && !$hasCoordinateFilter) {
                return response(['message' => 'Inserire almeno service_type, address o range di coordinate per la ricerca.'], 422);
            }

            if (
                ($latitudeMin['provided'] && $latitudeMin['value'] < -90)
                || ($latitudeMax['provided'] && $latitudeMax['value'] > 90)
            ) {
                return response(['message' => 'Range latitude non valido. Valori consentiti: da -90 a 90.'], 422);
            }

            if (
                ($longitudeMin['provided'] && $longitudeMin['value'] < -180)
                || ($longitudeMax['provided'] && $longitudeMax['value'] > 180)
            ) {
                return response(['message' => 'Range longitude non valido. Valori consentiti: da -180 a 180.'], 422);
            }

            if ($latitudeMin['provided'] && $latitudeMax['provided'] && $latitudeMin['value'] > $latitudeMax['value']) {
                return response(['message' => 'Range latitude non valido: lat_min deve essere minore o uguale a lat_max.'], 422);
            }

            if ($longitudeMin['provided'] && $longitudeMax['provided'] && $longitudeMin['value'] > $longitudeMax['value']) {
                return response(['message' => 'Range longitude non valido: lng_min deve essere minore o uguale a lng_max.'], 422);
            }

            $query = PetProfessionalService::query();

            if ($serviceType !== '') {
                $query->where('service_type', 'like', '%' . $this->escapeLike($serviceType) . '%');
            }

            if ($address !== '') {
                $query->where('address', 'like', '%' . $this->escapeLike($address) . '%');
            }

            if ($latitudeMin['provided']) {
                $query->where('latitude', '>=', $latitudeMin['value']);
            }

            if ($latitudeMax['provided']) {
                $query->where('latitude', '<=', $latitudeMax['value']);
            }

            if ($longitudeMin['provided']) {
                $query->where('longitude', '>=', $longitudeMin['value']);
            }

            if ($longitudeMax['provided']) {
                $query->where('longitude', '<=', $longitudeMax['value']);
            }
            $total = (clone $query)->count();

            return response([
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'items' => $query
                    ->orderBy('id', 'desc')
                    ->offset($offset)
                    ->limit($limit)
                    ->get()
                    ->toArray(),
            ]);
        } catch (\Exception $e) {
            return $this->internalError($e);
        }
    }

    public function save(Request $request, Response $response): Response
    {
        try {
            $payload = $this->validatePayload((array) $request->getParsedBody(), false);

            if (isset($payload['error'])) {
                return response(['message' => $payload['error']], 422);
            }

            $item = PetProfessionalService::query()->create($payload);

            return response($item->toArray(), 201);
        } catch (\Exception $e) {
            return $this->internalError($e);
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            $item = PetProfessionalService::query()->find((int) ($args['id'] ?? 0));

            if (!$item) {
                return response(['message' => 'Servizio non trovato.'], 404);
            }

            $payload = $this->validatePayload((array) $request->getParsedBody(), true);

            if (isset($payload['error'])) {
                return response(['message' => $payload['error']], 422);
            }

            if ($payload === []) {
                return response(['message' => 'Nessun campo valido da aggiornare.'], 422);
            }

            $item->fill($payload);
            $item->save();

            return response($item->fresh()->toArray());
        } catch (\Exception $e) {
            return $this->internalError($e);
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        try {
            $item = PetProfessionalService::query()->find((int) ($args['id'] ?? 0));

            if (!$item) {
                return response(['message' => 'Servizio non trovato.'], 404);
            }

            $item->delete();

            return response(['message' => 'Servizio eliminato correttamente.']);
        } catch (\Exception $e) {
            return $this->internalError($e);
        }
    }

    private function validatePayload(array $data, bool $isUpdate): array
    {
        $payload = [];
        $fields = [
            'first_name',
            'last_name',
            'company_name',
            'vat_number',
            'fiscal_code',
            'fiscal_data',
            'address',
            'latitude',
            'longitude',
            'service_type',
            'description',
            'media',
        ];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];

            if (in_array($field, ['fiscal_data', 'media'], true)) {
                if ($value !== null && !is_array($value)) {
                    return ['error' => $field . ' deve essere un array JSON valido.'];
                }

                $payload[$field] = $value;
                continue;
            }

            if (in_array($field, ['latitude', 'longitude'], true)) {
                if ($value === '' || $value === null) {
                    $payload[$field] = null;
                    continue;
                }

                if (!is_numeric($value)) {
                    return ['error' => $field . ' deve essere numerico.'];
                }

                $numericValue = (float) $value;
                $min = $field === 'latitude' ? -90 : -180;
                $max = $field === 'latitude' ? 90 : 180;

                if ($numericValue < $min || $numericValue > $max) {
                    return ['error' => $field . ' fuori range.'];
                }

                $payload[$field] = $numericValue;
                continue;
            }

            if ($value === null) {
                $payload[$field] = null;
                continue;
            }

            $payload[$field] = trim((string) $value);

            if ($field === 'service_type' && $payload[$field] !== '' && !in_array($payload[$field], self::ALLOWED_SERVICE_TYPES, true)) {
                return ['error' => 'service_type non valido. Valori consentiti: pet-sitting, toilettatore, allevamento.'];
            }
        }

        if (!$isUpdate) {
            foreach (['first_name', 'last_name', 'address', 'service_type'] as $required) {
                if (!isset($payload[$required]) || $payload[$required] === '') {
                    return ['error' => 'Campi obbligatori: first_name, last_name, address, service_type.'];
                }
            }
        }

        return $payload;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\%', '\_'],
            $value
        );
    }

    private function parseFloatQueryParam(array $queryParams, string $primaryKey, array $aliases = []): array
    {
        $keys = [$primaryKey, ...$aliases];

        foreach ($keys as $key) {
            if (!array_key_exists($key, $queryParams)) {
                continue;
            }

            $rawValue = trim((string) $queryParams[$key]);

            if ($rawValue === '') {
                return ['provided' => false, 'value' => null];
            }

            if (!is_numeric($rawValue)) {
                return ['provided' => false, 'value' => null, 'error' => $key . ' deve essere numerico.'];
            }

            return ['provided' => true, 'value' => (float) $rawValue];
        }

        return ['provided' => false, 'value' => null];
    }

    private function internalError(\Exception $e): Response
    {
        Log::error('Pet professional services API error', ['exception' => $e]);

        return response(['message' => 'Errore interno del server.'], 500);
    }
}
