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

    public function test_adds_user_id_for_valid_bearer_token(): void
    {
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication([
            'aws-cognito-client' => new class {
                public function decodeAccessToken(string $accessToken): array
                {
                    return ['sub' => 'customer-sub'];
                }
            },
        ]);

        $middleware = new OptionalAuthenticationMiddleware();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->with('Authorization')->willReturn('Bearer' . ' valid-token');

        $requestWithUser = $this->createMock(ServerRequestInterface::class);
        $request->expects($this->once())
            ->method('withAttribute')
            ->with('user_id', 'customer-sub')
            ->willReturn($requestWithUser);

        $response = $this->createMock(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with($requestWithUser)
            ->willReturn($response);

        $this->assertSame($response, $middleware->process($request, $handler));
    }
}
