<?php
declare(strict_types=1);

namespace PS\Webservice\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Authenticated server-to-server bridge to the Packlink module.
 *
 * It never calls InPost. The shared secret is used only between this
 * WebService and the installed Packlink module; it must never reach Storefront.
 */
final class PacklinkInpostGateway
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly string $endpoint,
        private readonly string $sharedSecret,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function request(array $payload): array
    {
        if ($this->endpoint === '' || $this->sharedSecret === '') {
            throw new PacklinkInpostGatewayException(503, 'Packlink pickup points are not configured.');
        }

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PacklinkInpostGatewayException(500, 'Unable to prepare the Packlink request.');
        }

        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $this->sharedSecret);

        try {
            $response = $this->client->request('POST', $this->endpoint, [
                'http_errors' => false,
                'connect_timeout' => 5,
                'timeout' => 15,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-Packlink-Headless-Timestamp' => $timestamp,
                    'X-Packlink-Headless-Signature' => $signature,
                ],
                'body' => $body,
            ]);
        } catch (GuzzleException) {
            throw new PacklinkInpostGatewayException(502, 'Packlink pickup points are temporarily unavailable.');
        }

        $status = $response->getStatusCode();
        $rawBody = (string) $response->getBody();
        $result = json_decode($rawBody, true);
        if (!is_array($result)) {
            throw new PacklinkInpostGatewayException(502, 'Packlink returned an invalid response.');
        }

        if ($status < 200 || $status >= 300) {
            $safeStatus = $status >= 500 ? 502 : 422;
            throw new PacklinkInpostGatewayException($safeStatus, 'The selected pickup point is no longer available.');
        }

        return $result;
    }
}

final class PacklinkInpostGatewayException extends \RuntimeException
{
    public function __construct(public readonly int $statusCode, string $message)
    {
        parent::__construct($message);
    }
}
