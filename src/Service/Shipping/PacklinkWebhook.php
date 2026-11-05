<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Shipping;

/** Validates a webhook registered with the integration-specific secret header. */
final class PacklinkWebhook
{
    private const EVENTS = [
        'shipment.carrier.success', 'shipment.carrier.fail', 'shipment.label.ready',
        'shipment.label.fail', 'shipment.tracking.update', 'shipment.delivered', 'shipment.carrier.delivered',
    ];

    public function __construct(private readonly string $secret)
    {
        if (strlen($secret) < 32 || preg_match('/[\r\n]/', $secret)) {
            throw new \InvalidArgumentException('A Packlink webhook secret of at least 32 bytes is required.');
        }
    }

    /**
     * Return only the provider reference. Consumers must read current state from
     * Packlink, rather than applying an event which may be duplicated or stale.
     */
    public function shipmentReference(string $header, string $body): string
    {
        if (!hash_equals($this->secret, $header)) {
            throw new \UnexpectedValueException('Invalid webhook authentication.');
        }
        if ($body === '' || strlen($body) > 65536) {
            throw new \InvalidArgumentException('Invalid webhook payload.');
        }
        $event = json_decode($body, true);
        $reference = is_array($event) ? ($event['data']['shipment_reference'] ?? null) : null;
        if (!is_array($event) || !isset($event['event']) || !is_string($event['event'])
            || !in_array($event['event'], self::EVENTS, true)
            || !isset($event['datetime']) || !is_string($event['datetime']) || trim($event['datetime']) === ''
            || !is_string($reference) || trim($reference) === '' || strlen($reference) > 191
            || preg_match('/[\x00-\x1f\x7f]/', $reference)) {
            throw new \InvalidArgumentException('Invalid webhook payload.');
        }
        return $reference;
    }
}
