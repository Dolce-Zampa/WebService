<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Http\Middleware\CachingMiddleware;
use PS\Webservice\Http\Middleware\NoStoreMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class SellerCacheIsolationTest extends TestCase
{
    public function test_seller_requests_never_reuse_another_users_cached_response(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public int $calls = 0;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->calls++;
                $response = new Response();
                $response->getBody()->write($request->getHeaderLine('Authorization'));
                return $response;
            }
        };
        $cache = new CachingMiddleware('seller', 10);
        foreach (['seller-a', 'seller-b'] as $token) {
            $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/seller/me')
                ->withHeader('Authorization', 'Bearer ' . $token);
            $response = $cache->process($request, $handler);
            self::assertSame('Bearer ' . $token, (string) $response->getBody());
            self::assertFalse($response->hasHeader('X-Cache'));
        }
        self::assertSame(2, $handler->calls);
    }

    public function test_private_responses_and_authentication_errors_are_not_cacheable(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(401)->withHeader('Cache-Control', 'public, max-age=3600');
            }
        };
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/seller/me');
        $response = (new NoStoreMiddleware())->process($request, $handler);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('private, no-store, max-age=0', $response->getHeaderLine('Cache-Control'));
    }
}
