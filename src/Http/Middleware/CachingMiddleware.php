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
    private ?int $ttl;
    private array $tag;

    public function __construct(string $tag = '', ?int $ttl = null) 
    {
        $this->tag = [$tag];
        $this->ttl = $ttl ?? (in_array($tag, ['product-detail', 'products', 'products,promotions', 'product-reviews', 'search'], true) ? 5 : null);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Skip caching for non-GET requests
        if ($request->getMethod() !== 'GET' || env('APP_DISABLE_CACHE', false)) {
            return $handler->handle($request);
        }

        $uri = $request->getUri()->getPath();

        $params = $request->getQueryParams();
        $clearCache = ($params['clear_cache'] ?? false) === 'true';
        unset($params['clear_cache'], $params['no_cache']);
        ksort($params);
        $queryParams = http_build_query($params);
        $cacheKey = 'api_cache:' . $uri . '?' . $queryParams;
        $tagEstract = $this->extractTagsFromParams($request->getQueryParams());

        $catalogTags = in_array($this->tag[0], ['product-detail', 'products', 'products,promotions', 'product-reviews', 'search'], true) ? ['product-catalog'] : [];
        $this->tags(array_merge($this->tag, ['api'], $tagEstract, $catalogTags));

        $cacheStore = \Illuminate\Support\Facades\Cache::tags($this->tags);
        $namespace = $cacheStore->getTags()->getNamespace();
        $revision = \Illuminate\Support\Facades\Cache::get(\PS\Webservice\Service\PS\Product::CACHE_REVISION, '0');

        //if param have no_cache=1 skip cache
        $skipCache = false;
        if (isset($request->getQueryParams()['no_cache']) && $request->getQueryParams()['no_cache'] == '1') {
            $skipCache = true;
        }

        if ($clearCache) {
            $this->removeFromCache($cacheKey);
        }

        // Try to get from cache
        if ($this->existsInCache($cacheKey) && $skipCache === false) {
            Log::debug("Cache hit for key: " . $cacheKey);
            $cachedData = $this->getFromCache($cacheKey);
            
            if (is_string($cachedData)) {
                $response = new \Slim\Psr7\Response();
                $response->getBody()->write($cachedData);
                return $response->withHeader('Content-Type', 'application/json')->withHeader('X-Cache', 'HIT');
            }

            if (is_array($cachedData)) {
                $response = response($cachedData);
                return $response->withHeader('X-Cache', 'HIT')
                               ->withHeader('X-Cache-Key', substr($cacheKey, 0, 16) . '...');
            }
        }

        // Process request
        $response = $handler->handle($request);

        // Cache only successful responses
        if (!$skipCache && $response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $body = $response->getBody()->__toString();
            if ($cacheStore->getTags()->getNamespace() === $namespace && \Illuminate\Support\Facades\Cache::get(\PS\Webservice\Service\PS\Product::CACHE_REVISION, '0') === $revision) {
                $this->setToCache($cacheKey, $body, $this->ttl);
            }
            
            return $response->withHeader('X-Cache', 'MISS')
                           ->withHeader('X-Cache-Key', substr($cacheKey, 0, 16) . '...');
        }

        return $response;
    }

    private function extractTagsFromParams(array $params): array
    {
        $tags = [];
        if(isset($params['manufacturer']) || isset($params['id_manufacturer'])) {
            $tags[] = ManufacturesMap::getManufacturer((int) ($params['manufacturer'] ?? $params['id_manufacturer']));
        }

        if(isset($params['category']) || isset($params['id_category'])) {
            $categories = explode('|', $params['category'] ?? $params['id_category']);
            foreach($categories as $category) {
                $tags[] = CategoriesMap::getCategory((int)$category);
            }
        }

        return $tags;
    }
}