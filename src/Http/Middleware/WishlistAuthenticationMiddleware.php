<?php

declare(strict_types=1);

namespace PS\Webservice\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Verify access-token claims after the shared middleware verifies the signature. */
final class WishlistAuthenticationMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $validator = new class($handler) implements RequestHandlerInterface {
            public function __construct(private readonly RequestHandlerInterface $next) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $claims = $request->getAttribute('auth_claims');
                $region = (string) env('AWS_DEFAULT_REGION', 'eu-west-1');
                $pool = (string) env('AWS_COGNITO_USER_POOL_ID', '');
                $client = (string) env('AWS_COGNITO_CLIENT_ID', '');
                $issuer = 'https://cognito-idp.' . $region . '.amazonaws.com/' . $pool;
                $now = time();
                if (!is_array($claims) || $region === '' || $pool === '' || $client === ''
                    || !is_string($claims['sub'] ?? null) || $claims['sub'] === ''
                    || ($claims['iss'] ?? null) !== $issuer || ($claims['token_use'] ?? null) !== 'access'
                    || ($claims['client_id'] ?? null) !== $client
                    || !is_int($claims['exp'] ?? null) || $claims['exp'] <= $now
                    || (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > $now))) {
                    return response(['message' => 'Unauthorized'], 401, ['Cache-Control' => 'no-store']);
                }
                return $this->next->handle($request);
            }
        };
        return (new AuthenticationMiddleware())->process($request, $validator);
    }
}
