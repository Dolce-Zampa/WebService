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
            return new \GuzzleHttp\Psr7\Response(401, [], json_encode(['error' => 'Unauthorized: Invalid token']));
        }

        $authToken = substr($authHeader, 7);
        if ($authToken === '') {
            return new \GuzzleHttp\Psr7\Response(401, [], json_encode(['error' => 'Unauthorized: Empty token']));
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

        return new \GuzzleHttp\Psr7\Response(401, [], json_encode(['error' => 'Unauthorized: Invalid token']));
    }
}
