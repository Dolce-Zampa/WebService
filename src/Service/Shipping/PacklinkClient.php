<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Shipping;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/** Thin client for the v1 endpoints used by Packlink's own integration core. */
final class PacklinkClient
{
    private readonly string $baseUrl;

    public function __construct(private readonly ClientInterface $http, string $baseUrl, private readonly string $apiKey)
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || trim($apiKey) === '' || preg_match('/[\r\n]/', $apiKey)) {
            throw new \InvalidArgumentException('Invalid Packlink API configuration.');
        }
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
    }

    /** The provider's response to POST /shipments contains a reference, not a booking confirmation. */
    public function createDraft(array $draft): string
    {
        $result = $this->request('POST', 'shipments', $draft);
        $reference = $result['reference'] ?? null;
        if (!is_string($reference) || trim($reference) === '') {
            throw new PacklinkException('create_draft_invalid_response');
        }
        return $reference;
    }

    public function shipment(string $reference): array
    {
        return $this->request('GET', 'shipments/' . $this->reference($reference));
    }

    public function labels(string $reference): array
    {
        return $this->request('GET', 'shipments/' . $this->reference($reference) . '/labels');
    }

    public function tracking(string $reference): array
    {
        return $this->request('GET', 'shipments/' . $this->reference($reference) . '/track');
    }

    private function reference(string $reference): string
    {
        if ($reference === '' || strlen($reference) > 255 || preg_match('/[\x00-\x1f\x7f]/', $reference)) {
            throw new \InvalidArgumentException('Invalid shipment reference.');
        }
        return rawurlencode($reference);
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $options = [
            'headers' => ['Authorization' => $this->apiKey, 'Accept' => 'application/json'],
            'timeout' => 30, 'connect_timeout' => 5, 'allow_redirects' => false,
            'http_errors' => false, 'verify' => true,
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }
        $operation = $method === 'POST' ? 'create_draft' : 'read_shipment';
        try {
            $response = $this->http->request($method, $this->baseUrl . 'v1/' . $path, $options);
        } catch (GuzzleException $exception) {
            throw new PacklinkException($operation);
        }
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new PacklinkException($operation, $status);
        }
        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new PacklinkException($operation . '_invalid_response', $status);
        }
        return $decoded;
    }
}
