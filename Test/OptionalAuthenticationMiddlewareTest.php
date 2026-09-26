<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Http\Middleware\OptionalAuthenticationMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class OptionalAuthenticationMiddlewareTest extends TestCase
{
    public function test_allows_requests_without_authorization_header(): void
    {
        $middleware = new OptionalAuthenticationMiddleware();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->with('Authorization')->willReturn('');

        $response = $this->createMock(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with($request)
            ->willReturn($response);

        $this->assertSame($response, $middleware->process($request, $handler));
    }

    public function test_rejects_non_bearer_authorization_headers(): void
    {
        $middleware = new OptionalAuthenticationMiddleware();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->with('Authorization')->willReturn('Basic abc123');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $middleware->process($request, $handler);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_rejects_empty_bearer_tokens(): void
    {
        $middleware = new OptionalAuthenticationMiddleware();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->with('Authorization')->willReturn('Bearer ');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $middleware->process($request, $handler);

        $this->assertSame(401, $response->getStatusCode());
    }
}
