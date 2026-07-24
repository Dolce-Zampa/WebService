<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Middleware;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Traits\UseCache;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AuthenticationMiddleware implements MiddlewareInterface
{
    use UseCache;
    private ?int $ttl;

    public function __construct(?int $ttl = null) 
    {
        $this->ttl = $ttl;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if (!$authHeader) {
            return new \GuzzleHttp\Psr7\Response(401, [], json_encode(['error' => 'Unauthorized: Missing Authorization header']));
        }

        $authToken = str_replace('Bearer ', '', $authHeader);
        if (empty($authToken)) {
            return new \GuzzleHttp\Psr7\Response(401, [], json_encode(['error' => 'Unauthorized: Empty token']));
        }

        try {
            $decodedToken = \PS\Webservice\Facades\AwsCognitoClient::decodeAccessToken($authToken);
            if (isset($decodedToken['sub'])) {
                $userId = $decodedToken['sub'];
                $request = $request->withAttribute('user_id', $userId);
            } else {
                return new \GuzzleHttp\Psr7\Response(401, [], json_encode(['error' => 'Unauthorized: Invalid token']));
            }
        } catch (\Throwable $e) {
            Log::error('Authentication error: ' . $e->getMessage(), ['exception' => $e]);
            return new \GuzzleHttp\Psr7\Response(401, [], json_encode(['error' => 'Unauthorized: Invalid token']));
        }

        return $handler->handle($request);

    }

}