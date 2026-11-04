<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Middleware;

use PS\Webservice\Domain\Models\PS\Manufacturers\Manufacturer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SellerAuthorizationMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $sub = $request->getAttribute('user_id');
        if (!is_string($sub) || $sub === '') {
            return response(['error' => 'Unauthorized'], 401);
        }

        $manufacturer = Manufacturer::query()->where('sub', $sub)->first();
        if ($manufacturer === null) {
            return response(['error' => 'Forbidden'], 403);
        }

        return $handler->handle($request->withAttribute('authenticated_manufacturer_id', (int) $manufacturer->id_manufacturer));
    }
}
