<?php
declare(strict_types=1);

namespace PS\Webservice\Service\GiftCard;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/** Server-to-server client; gift card codes and API credentials never enter URLs or logs. */
final class GiftCardClient
{
    private const ERRORS = [
        'invalid_input', 'invalid_currency', 'invalid_idempotency_key', 'currency_mismatch',
        'insufficient_balance', 'cart_reserved', 'card_unavailable', 'card_inactive',
        'card_expired', 'card_blocked', 'reservation_conflict', 'gift_card_purchase',
        'unauthorized', 'forbidden', 'rate_limited', 'temporarily_unavailable',
        'idempotency_conflict', 'order_unavailable', 'order_already_redeemed', 'cart_unavailable', 'payment_too_small',
        'cart_mismatch', 'customer_unavailable', 'order_mismatch', 'order_unpaid', 'reservation_unavailable',
    ];

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $url,
        private readonly string $apiKey,
        private readonly bool $verifyTls = true,
    ) {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Gift card API must use an HTTPS URL without credentials or a fragment.');
        }
        if (!preg_match('/^gc_api_[a-f0-9]{64}$/D', $apiKey)) {
            throw new \InvalidArgumentException('Gift card API key is invalid.');
        }
        parse_str($parts['query'] ?? '', $query);
        if (($query['fc'] ?? null) !== 'module' || ($query['module'] ?? null) !== 'mlabgiftcard'
            || ($query['controller'] ?? null) !== 'api'
            || isset($query['action']) || isset($query['api_key'])) {
            throw new \InvalidArgumentException('Gift card API URL must target the module API without an action or key.');
        }
    }

    /** TLS verification can be disabled only by explicit opt-in in non-production environments. */
    public static function verifyTlsForEnvironment(string $environment, mixed $allowInsecureTls): bool
    {
        $development = in_array(strtolower(trim($environment)), ['local', 'dev', 'development', 'testing', 'test'], true);
        return !($development && filter_var($allowInsecureTls, FILTER_VALIDATE_BOOLEAN));
    }

    /**
     * The idempotency key must identify the durable checkout attempt, not the
     * caller's HTTP request. Retrying after a timeout must reuse the same key.
     *
     * @return array<string, mixed>
     */
    public function reserve(string $code, int $cartId, ?int $customerId, int $totalCents, string $idempotencyKey, int $ttlSeconds): array
    {
        if ($code === '' || strlen($code) > 64 || $cartId < 1 || $totalCents < 1
            || ($customerId !== null && $customerId < 1) || $ttlSeconds < 60 || $ttlSeconds > 604800) {
            throw new \InvalidArgumentException('Invalid gift card reservation request.');
        }
        $data = [
            'code' => $code,
            'cart_id' => $cartId,
            'currency' => 'EUR',
            'amount_cents' => $totalCents,
            'allow_partial' => true,
            'ttl_seconds' => $ttlSeconds,
        ];
        if ($customerId !== null) {
            $data['customer_id'] = $customerId;
        }
        return $this->post('reserve', $data, $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function capture(string $reservationId, int $orderId, string $idempotencyKey, ?int $pendingStateId = null): array
    {
        $this->assertReservationId($reservationId);
        if ($orderId < 1) {
            throw new \InvalidArgumentException('Invalid order ID.');
        }
        if ($pendingStateId !== null && $pendingStateId < 1) {
            throw new \InvalidArgumentException('Invalid pending order state.');
        }
        $payload = ['reservation_id' => $reservationId, 'order_id' => $orderId];
        if ($pendingStateId !== null) {
            $payload['pending_state_id'] = $pendingStateId;
        }
        return $this->post('capture', $payload, $idempotencyKey);
    }

    /** Mark a reservation for settlement after the external payment is verified. */
    public function protect(string $reservationId, string $idempotencyKey): array
    {
        $this->assertReservationId($reservationId);
        return $this->post('protect', ['reservation_id' => $reservationId], $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function release(string $reservationId, string $idempotencyKey): array
    {
        $this->assertReservationId($reservationId);
        return $this->post('release', ['reservation_id' => $reservationId, 'reason' => 'Checkout not completed'], $idempotencyKey);
    }

    private function assertReservationId(string $reservationId): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $reservationId)) {
            throw new \InvalidArgumentException('Invalid gift card reservation ID.');
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function post(string $action, array $data, string $idempotencyKey): array
    {
        if (!in_array($action, ['lookup', 'reserve', 'protect', 'capture', 'release'], true)) {
            throw new \InvalidArgumentException('Unsupported gift card operation.');
        }
        if (!preg_match('~^[A-Za-z0-9._:/-]{8,128}$~D', $idempotencyKey) || array_key_exists('api_key', $data)) {
            throw new \InvalidArgumentException('Invalid gift card request.');
        }
        return $this->request($action, $data, $idempotencyKey);
    }

    /** Process queued deliveries and expire old reservations. */
    public function maintenance(): array
    {
        return $this->request('maintenance', [], null);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function request(string $action, array $data, ?string $idempotencyKey): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        try {
            $response = $this->http->request('POST', $this->url . '&action=' . $action, [
                'headers' => $headers,
                'json' => $data + ['api_key' => $this->apiKey],
                'verify' => $this->verifyTls,
                'allow_redirects' => false,
                'http_errors' => false,
                'connect_timeout' => 5,
                // Maintenance can send a batch of 20 messages synchronously.
                'timeout' => $action === 'maintenance' ? 120 : 15,
            ]);
        } catch (GuzzleException $error) {
            // Request exceptions can contain credentials and gift card codes.
            throw new GiftCardApiException('temporarily_unavailable', 503);
        }
        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);
        if ($status >= 200 && $status < 300 && is_array($body)
            && ($body['success'] ?? false) === true && is_array($body['data'] ?? null)) {
            return $body['data'];
        }
        $code = is_array($body) ? ($body['error']['code'] ?? null) : null;
        $safeCode = is_string($code) && in_array($code, self::ERRORS, true) ? $code : 'temporarily_unavailable';
        throw new GiftCardApiException($safeCode, $status >= 400 && $status <= 599 ? $status : 503);
    }
}
