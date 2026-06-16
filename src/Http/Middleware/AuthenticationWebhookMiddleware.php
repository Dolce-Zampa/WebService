<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Middleware;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Traits\UseCache;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AuthenticationWebhookMiddleware implements MiddlewareInterface
{
    use UseCache;
    private ?int $ttl;

    public function __construct(?int $ttl = null) 
    {
        $this->ttl = $ttl;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
       // Authenticate the incoming webhook with the shared secret
        $incomingSecret = $request->getHeaderLine('X-Webhook-Secret');
        $expectedSecret = env('WEBHOOK_SECRET', '');

        if (empty($expectedSecret) || !hash_equals($expectedSecret, $incomingSecret)) {
            Log::warning('PrestashopProductWebhook: invalid or missing X-Webhook-Secret');
            return response(['error' => 'Unauthorized'], 401);
        }
        
        return $handler->handle($request);

    }

}