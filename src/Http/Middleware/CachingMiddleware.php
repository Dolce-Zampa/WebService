<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Middleware;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CachingMiddleware implements MiddlewareInterface
{
    private int $ttl = 3600; // 1 hour default

    public function __construct(int $ttl = 3600)
    {
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
        $cacheKey = 'api_cache:' . sha1($uri . '?' . $queryParams);

        Log::debug("Invoking URL " . $uri . '?' . $queryParams);

        // Try to get from cache
        if (Cache::has($cacheKey)) {
            $cachedData = Cache::get($cacheKey);
            
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
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $body = $response->getBody()->__toString();
            Cache::put($cacheKey, $body, $this->ttl);
            
            return $response->withHeader('X-Cache', 'MISS')
                           ->withHeader('X-Cache-Key', substr($cacheKey, 0, 16) . '...');
        }

        return $response;
    }
}