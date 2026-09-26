<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Middleware;

use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class OptionalAuthenticationMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if ($authHeader === '') {
            return $handler->handle($request);
        }

        if (strpos($authHeader, 'Bearer ') !== 0) {
            return response(['error' => 'Unauthorized: Invalid token'], 401);
        }

        $authToken = substr($authHeader, 7);
        if ($authToken === '') {
            return response(['error' => 'Unauthorized: Empty token'], 401);
        }

        try {
            $decodedToken = \PS\Webservice\Facades\AwsCognitoClient::decodeAccessToken($authToken);
            if (isset($decodedToken['sub'])) {
                $request = $request->withAttribute('user_id', $decodedToken['sub']);
                return $handler->handle($request);
            }
        } catch (\Throwable $e) {
            Log::error('Optional authentication error: ' . $e->getMessage(), ['exception' => $e]);
        }

        return response(['error' => 'Unauthorized: Invalid token'], 401);
    }
}
