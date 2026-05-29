<?php

declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Domain\Models\PetProfessionalService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PetProfessionalServiceController extends Controller
{
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
            $page = max(1, (int) ($queryParams['page'] ?? 1));
            $limit = min(100, max(1, (int) ($queryParams['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;

            if ($serviceType === '' && $address === '') {
                return response(['message' => 'Inserire almeno service_type o address per la ricerca.'], 422);
            }

            $query = PetProfessionalService::query();

            if ($serviceType !== '') {
                $query->where('service_type', 'like', '%' . $this->escapeLike($serviceType) . '%');
            }

            if ($address !== '') {
                $query->where('address', 'like', '%' . $this->escapeLike($address) . '%');
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

            if ($value === null) {
                $payload[$field] = null;
                continue;
            }

            $payload[$field] = trim((string) $value);
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

    private function internalError(\Exception $e): Response
    {
        Log::error('Pet professional services API error', ['exception' => $e]);

        return response(['message' => 'Errore interno del server.'], 500);
    }
}
