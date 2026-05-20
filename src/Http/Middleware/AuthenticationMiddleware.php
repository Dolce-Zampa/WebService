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
        // check if exist the auth header
        // at the moment the token is a static token getting from env variable, in future we can implement a more complex authentication system
        //FIXME: implement a more complex authentication system
        $authHeader = $request->getHeaderLine('Authorization');
        $token = str_replace('Bearer ', '', $authHeader);
        $expectedToken = env('API_AUTH_TOKEN', 'secret-token');
        if ($token !== $expectedToken) {
            return response(['error' => 'Unauthorized'], 401);
        }

        return $handler->handle($request);

    }

}