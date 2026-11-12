<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Middleware;

use PS\Webservice\Facades\AwsCognitoClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Accept guest requests, but never derive a customer identity from client input. */
final class OptionalAuthenticationMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(\S+)$/iD', $header, $match)) {
            try {
                $claims = AwsCognitoClient::decodeAccessToken($match[1]);
                if (is_array($claims) && is_string($claims['sub'] ?? null) && $claims['sub'] !== '') {
                    $request = $request->withAttribute('user_id', $claims['sub'])->withAttribute('auth_claims', $claims);
                }
            } catch (\Throwable $error) {
                // An invalid token is treated as unauthenticated; guest checkout remains available.
            }
        }
        return $handler->handle($request);
    }
}
