<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Middleware;

use PS\Webservice\Traits\UuidGenerator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class EncodeIdMiddleware implements MiddlewareInterface
{
    use UuidGenerator;

    const FIELDS = ['id_customer', 'id_cart', 'id_order', 'id_guest', 'id_product', 'parent_id', 'cartId','id_manufacturer','id_supplier'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $body = $response->getBody();
        $body->rewind();
        $data = json_decode($body->getContents(), true);

        if (is_array($data)) {
            $data = $this->encodeRecursive($data);
            $body->rewind();
            $body->write(json_encode($data));
        }

        return $response->withBody($body);
    }

    private function encodeRecursive(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, self::FIELDS, true) && !is_array($value)) {
                $data[$key] = $this->encodeId($value, $key);
            } elseif (is_array($value)) {
                $data[$key] = $this->encodeRecursive($value);
            }
        }
        return $data;
    }
}