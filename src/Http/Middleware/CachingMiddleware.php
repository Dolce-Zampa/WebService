<?php

declare(strict_types=1);

namespace PS\Webservice\Http\Middleware;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Domain\Enums\CategoriesMap;
use PS\Webservice\Domain\Enums\ManufacturesMap;
use PS\Webservice\Traits\UseCache;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CachingMiddleware implements MiddlewareInterface
{
    use UseCache;

    private const DEFAULT_TTL = 5; // minuti

    /**
     * Tag "statico" configurato in fase di registrazione della rotta
     * (es. 'products'). NON viene mai mutato dopo il costruttore:
     * i tag dinamici per-richiesta vengono calcolati localmente in
     * process() e non toccano questa property.
     */
    private array $baseTags;
    private int $ttl;

    public function __construct(string $tag = '', ?int $ttl = null)
    {
        $this->baseTags = $tag !== '' ? [$tag] : [];
        $this->ttl = $ttl ?? self::DEFAULT_TTL;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Skip caching for non-GET requests
        if ($request->getMethod() !== 'GET' || env('APP_DISABLE_CACHE', false)) {
            return $handler->handle($request);
        }
        // Cache solo per GET: su POST/PUT/DELETE/PATCH un cache-hit
        // salterebbe l'esecuzione dell'handler, cioè l'operazione di
        // scrittura non verrebbe mai eseguita.
        if ($request->getMethod() !== 'GET') {
            return $handler->handle($request);
        }

        $queryParams = $request->getQueryParams();

        $skipCache = isset($queryParams['no_cache']) && $queryParams['no_cache'] === '1';

        $cacheKey = $this->buildCacheKey($request, $queryParams);

        $tags = array_merge(
            $this->baseTags,
            $this->extractTagsFromParams($queryParams),
            ['api']
        );
        $this->tags($tags);

        if (!$skipCache) {
            $cachedData = $this->getFromCache($cacheKey);

            if ($cachedData !== null) {
                Log::debug('Cache hit for key: ' . $cacheKey);

                $decoded = $this->decodeCachedPayload($cachedData);

                if ($decoded !== null) {
                    return response($decoded)
                        ->withHeader('X-Cache', 'HIT')
                        ->withHeader('X-Cache-Key', $this->obfuscateKey($cacheKey));
                }
            }
        }

        $response = $handler->handle($request);

        if (!$skipCache && $response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $body = $response->getBody()->__toString();

            $decodedBody = json_decode($body, true);

            // Salviamo sempre lo stesso formato che ci aspettiamo di
            // rileggere: array decodificato, non il body grezzo.
            if (json_last_error() === JSON_ERROR_NONE && is_array($decodedBody)) {
                $this->setToCache($cacheKey, $decodedBody, $this->ttl);
            }

            return $response
                ->withHeader('X-Cache', 'MISS')
                ->withHeader('X-Cache-Key', $this->obfuscateKey($cacheKey));
        }

        return $response;
    }

    private function buildCacheKey(ServerRequestInterface $request, array $queryParams): string
    {
        $uri = $request->getUri()->getPath();

        // Normalizziamo l'ordine dei parametri per evitare che
        // ?a=1&b=2 e ?b=2&a=1 producano due chiavi diverse.
        ksort($queryParams);

        return 'api_cache:' . $uri . '?' . http_build_query($queryParams);
    }

    /**
     * Decodifica il payload salvato in cache. Ritorna null se il
     * contenuto non è nel formato atteso (array), così il chiamante
     * può trattarlo come un cache-miss anziché rompersi.
     */
    private function decodeCachedPayload(mixed $cachedData): ?array
    {
        if (is_array($cachedData)) {
            return $cachedData;
        }

        if (is_string($cachedData)) {
            $decoded = json_decode($cachedData, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function obfuscateKey(string $cacheKey): string
    {
        return substr($cacheKey, 0, 16) . '...';
    }

    private function extractTagsFromParams(array $params): array
    {
        $tags = [];

        if (isset($params['manufacturer']) || isset($params['id_manufacturer'])) {
            $manufacturerId = (int) ($params['manufacturer'] ?? $params['id_manufacturer']);
            $tag = ManufacturesMap::getManufacturer($manufacturerId);

            if ($tag !== null && $tag !== '') {
                $tags[] = $tag;
            }
        }

        if (isset($params['category']) || isset($params['id_category'])) {
            $categories = explode('|', (string) ($params['category'] ?? $params['id_category']));

            foreach ($categories as $category) {
                $tag = CategoriesMap::getCategory((int) $category);

                if ($tag !== null && $tag !== '') {
                    $tags[] = $tag;
                }
            }
        }

        return $tags;
    }
}