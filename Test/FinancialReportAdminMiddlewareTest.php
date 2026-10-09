<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Http\Middleware\FinancialReportAdminMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class FinancialReportAdminMiddlewareTest extends TestCase
{
    public function test_allows_only_configured_administrator_groups(): void
    {
        $middleware = new FinancialReportAdminMiddleware(['financial-admin']);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface { return new Response(204); }
        };

        $allowed = $middleware->process((new ServerRequest('GET', '/'))->withAttribute('auth_claims', ['cognito:groups' => ['financial-admin']]), $handler);
        $denied = $middleware->process((new ServerRequest('GET', '/'))->withAttribute('auth_claims', ['cognito:groups' => ['seller']]), $handler);

        self::assertSame(204, $allowed->getStatusCode());
        self::assertSame(403, $denied->getStatusCode());
    }
}
