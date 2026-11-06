<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Shipping;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/** Authenticated access to the dedicated PrestaShop module controller. */
final class PrestashopShippingClient
{
    private readonly string $url;

    public function __construct(private readonly ClientInterface $http, string $baseUrl, private readonly string $key, private readonly string $shippingSecret)
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || trim($key) === '' || preg_match('/[\r\n]/', $key)
            || strlen($shippingSecret) < 32 || preg_match('/[\r\n]/', $shippingSecret)) {
            throw new \InvalidArgumentException('Invalid PrestaShop shipping API configuration.');
        }
        $this->url = rtrim($baseUrl, '/') . '/index.php';
    }

    /** @return int[] */
    public function candidates(string $since, array $states, int $after = 0): array
    {
        $response = $this->request('GET', ['since' => $since, 'states' => implode(',', $states), 'after' => $after]);
        $ids = $response['order_ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids)) {
            throw new \RuntimeException('Invalid PrestaShop candidate response.');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new \RuntimeException('Invalid PrestaShop candidate response.');
            }
        }
        return $ids;
    }

    public function order(int $orderId): array
    {
        if ($orderId <= 0) {
            throw new \InvalidArgumentException('Invalid order ID.');
        }
        $result = $this->request('GET', ['id_order' => $orderId]);
        if (!isset($result['order']) || !is_array($result['order']) || ($result['order']['id'] ?? null) !== $orderId) {
            throw new \RuntimeException('Invalid PrestaShop order response.');
        }
        return $result['order'];
    }

    public function applySnapshot(array $snapshot): array
    {
        return $this->request('POST', [], $snapshot);
    }

    public function sendLabel(int $orderId, int $sellerId, array $attachments): void
    {
        $result = $this->request('POST', [], ['action' => 'send_label', 'order_id' => $orderId,
            'seller_id' => $sellerId, 'attachments' => $attachments]);
        if (($result['sent'] ?? false) !== true) {
            throw new \RuntimeException('Label notification was not confirmed.');
        }
    }

    private function request(string $method, array $query, ?array $body = null): array
    {
        $options = [
            'query' => ['fc' => 'module', 'module' => 'webserviceapi', 'controller' => 'packlinkshipping'] + $query,
            'headers' => ['X-WS-Key' => $this->key, 'X-Packlink-Shipping-Secret' => $this->shippingSecret, 'Accept' => 'application/json'],
            'timeout' => 30, 'connect_timeout' => 5, 'allow_redirects' => false, 'http_errors' => false, 'verify' => true,
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }
        try {
            $response = $this->http->request($method, $this->url, $options);
        } catch (GuzzleException $exception) {
            throw new \RuntimeException('PrestaShop shipping API request failed.');
        }
        $body = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300
            || !is_array($body) || ($body['success'] ?? null) !== true || !is_array($body['data'] ?? null)) {
            throw new \RuntimeException('PrestaShop shipping API returned an error.');
        }
        return $body['data'];
    }
}
