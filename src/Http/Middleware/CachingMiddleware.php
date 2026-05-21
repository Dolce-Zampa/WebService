<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Middleware;

use Illuminate\Support\Facades\Log;
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
        $this->ttl = $ttl;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Skip caching for non-GET requests
        if ($request->getMethod() !== 'GET') {
            return $handler->handle($request);
        }

        $uri = $request->getUri()->getPath();
        $queryParams = http_build_query($request->getQueryParams());
        $cacheKey = 'api_cache:' . $uri . '?' . $queryParams;

        $this->tags($this->tag);

        //if param have no_cache=1 skip cache
        $skipCache = false;
        if (isset($request->getQueryParams()['no_cache']) && $request->getQueryParams()['no_cache'] == '1') {
            $skipCache = true;
        }

        // Try to get from cache
        if ($this->existsInCache($cacheKey) && $skipCache === false) {
            Log::debug("Cache hit for key: " . $cacheKey);
            $cachedData = $this->getFromCache($cacheKey);
            
            if (is_string($cachedData)) {
                $decoded = json_decode($cachedData, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $response = response($decoded['data']);
                    return $response->withHeader('X-Cache', 'HIT')
                                   ->withHeader('X-Cache-Key', substr($cacheKey, 0, 16) . '...');
                }
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
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() <= 300) {
            $body = $response->getBody()->__toString();
            $this->setToCache($cacheKey, $body, $this->ttl);
            
            return $response->withHeader('X-Cache', 'MISS')
                           ->withHeader('X-Cache-Key', substr($cacheKey, 0, 16) . '...');
        }

        return $response;
    }
}