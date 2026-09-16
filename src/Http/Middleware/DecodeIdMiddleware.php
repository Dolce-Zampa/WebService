<?php

namespace PS\Webservice\Http\Middleware;

use PS\Webservice\Traits\UuidGenerator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;

class DecodeIdMiddleware implements MiddlewareInterface
{
    use UuidGenerator;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $body = $request->getParsedBody();

        if (is_array($body)) {
            foreach (EncodeIdMiddleware::FIELDS as $field) {
                if (isset($body[$field])) {
                    $body[$field] = $this->decodeId($body[$field], $field);
                }
            }

            $request = $request->withParsedBody($body);
        }

        // Decode params
        $queryParams = $request->getQueryParams();
        foreach (EncodeIdMiddleware::FIELDS as $field) {
            if (isset($queryParams[$field])) {
                $queryParams[$field] = $this->decodeId($queryParams[$field], $field);
            }
        }

        // Passa la request modificata all'handler, response invariata
        return $handler->handle($request);
    }
}