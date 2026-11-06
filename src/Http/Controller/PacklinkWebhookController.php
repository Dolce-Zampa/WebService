<?php

declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use Illuminate\Database\ConnectionInterface;
use PS\Webservice\Service\Shipping\PacklinkWebhook;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PacklinkWebhookController
{
    public function __construct(private readonly ConnectionInterface $db, private readonly string $secret)
    {
    }

    public function handle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $verifier = new PacklinkWebhook($this->secret);
        } catch (\InvalidArgumentException $exception) {
            return $response->withStatus(503);
        }
        try {
            $body = $request->getBody()->read(65537);
            $reference = $verifier->shipmentReference($request->getHeaderLine('X-Packlink-Webhook-Secret'), $body);
        } catch (\UnexpectedValueException $exception) {
            return $response->withStatus(401);
        } catch (\InvalidArgumentException $exception) {
            return $response->withStatus(400);
        }
        try {
            $query = $this->db->table('packlink_shipments')->where('provider_reference', $reference)->where('status', 'created');
            if (!(clone $query)->exists()) {
                return $response->withStatus(404);
            }
            $query->update(['sync_due_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        } catch (\Throwable $exception) {
            return $response->withStatus(503);
        }
        return $response->withStatus(202);
    }
}
