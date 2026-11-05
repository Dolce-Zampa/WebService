<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Shipping;

/** Builds the Packlink draft shape from already-authorized order data. */
final class PacklinkDraftFactory
{
    public function build(int $orderId, array $order, array $configuration): array
    {
        if ($orderId <= 0) {
            throw new \InvalidArgumentException('Invalid order ID.');
        }
        foreach (['user_id', 'client_id', 'platform', 'platform_country', 'source', 'service_id'] as $key) {
            if (!isset($configuration[$key]) || !is_string($configuration[$key]) || trim($configuration[$key]) === '') {
                throw new \InvalidArgumentException('Missing Packlink draft configuration: ' . $key);
            }
        }
        $from = $this->address($configuration['from'] ?? null);
        $to = $this->address($order['to'] ?? null);
        $parcels = $order['packages'] ?? null;
        if (!is_array($parcels) || !array_is_list($parcels) || $parcels === []) {
            throw new \InvalidArgumentException('At least one package is required.');
        }
        $packages = [];
        foreach ($parcels as $parcel) {
            if (!is_array($parcel)) {
                throw new \InvalidArgumentException('Invalid package.');
            }
            $package = [];
            foreach (['weight', 'width', 'height', 'length'] as $dimension) {
                $value = $parcel[$dimension] ?? null;
                if (!is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0) {
                    throw new \InvalidArgumentException('Invalid package ' . $dimension . '.');
                }
                $package[$dimension] = $dimension === 'weight' ? round((float) $value, 2) : (int) ceil((float) $value);
                if ($package[$dimension] <= 0) {
                    throw new \InvalidArgumentException('Package ' . $dimension . ' is too small.');
                }
            }
            $packages[] = $package;
        }
        $value = $order['content_value'] ?? null;
        $currency = $order['currency'] ?? null;
        $content = $order['content'] ?? null;
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0
            || !is_string($currency) || !preg_match('/^[A-Z]{3}$/', $currency)
            || !is_string($content) || trim($content) === '' || mb_strlen($content) > 60) {
            throw new \InvalidArgumentException('Invalid shipment content.');
        }
        $draft = [
            'user_id' => $configuration['user_id'], 'client_id' => $configuration['client_id'],
            'platform' => $configuration['platform'], 'platform_country' => $configuration['platform_country'],
            'source' => $configuration['source'], 'service_id' => $configuration['service_id'],
            'from' => $from, 'to' => $to, 'packages' => $packages,
            'content' => $content, 'contentvalue' => round((float) $value, 2),
            'contentValue_currency' => $currency, 'content_second_hand' => false,
            'shipment_custom_reference' => 'order-' . $orderId,
            'additional_data' => ['order_id' => (string) $orderId],
        ];
        if (isset($order['dropoff_point_id'])) {
            if (!is_string($order['dropoff_point_id']) || trim($order['dropoff_point_id']) === '') {
                throw new \InvalidArgumentException('Invalid drop-off point.');
            }
            $draft['dropoff_point_id'] = $order['dropoff_point_id'];
        }
        if (isset($configuration['integration_id']) && is_string($configuration['integration_id']) && $configuration['integration_id'] !== '') {
            $draft['additional_data']['integration_id'] = $configuration['integration_id'];
        }
        return $draft;
    }

    private function address(mixed $address): array
    {
        if (!is_array($address)) {
            throw new \InvalidArgumentException('Shipment address is required.');
        }
        foreach (['name', 'surname', 'street1', 'zip_code', 'city', 'country', 'phone', 'email'] as $field) {
            if (!isset($address[$field]) || !is_string($address[$field]) || trim($address[$field]) === '') {
                throw new \InvalidArgumentException('Missing shipment address field: ' . $field);
            }
        }
        return array_intersect_key($address, array_flip(['name', 'surname', 'company', 'street1', 'street2', 'zip_code', 'city', 'country', 'phone', 'email']));
    }
}
