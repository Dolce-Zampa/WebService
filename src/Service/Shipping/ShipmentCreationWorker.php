<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Shipping;

use Illuminate\Database\ConnectionInterface;

/** Scheduled draft creation; never called from checkout. */
final class ShipmentCreationWorker
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly PrestashopShippingClient $shop,
        private readonly ShipmentDraftCreator $creator,
        private readonly PacklinkDraftFactory $factory,
        private readonly array $config,
    ) {
        $since = $config['since'] ?? '';
        $date = is_string($since) ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $since) : false;
        if (!$date || $date->format('Y-m-d H:i:s') !== $since || !isset($config['eligible_states'])
            || !is_array($config['eligible_states']) || $config['eligible_states'] === []
            || array_filter($config['eligible_states'], static fn ($state) => !is_int($state) || $state <= 0)
            || !isset($config['carriers']) || !is_array($config['carriers']) || $config['carriers'] === []) {
            throw new \InvalidArgumentException('Invalid shipping activation, state or carrier configuration.');
        }
    }

    /** Mirrors Packlink's grouped parcel calculation; fallback sizes are configured in cm/kg. */
    private function package(array $order): array
    {
        $units = ['kg' => 1.0, 'g' => 0.001, 'oz' => 0.02834952, 'lbs' => 0.45359237];
        $weightUnit = strtolower((string) ($order['weight_unit'] ?? ''));
        if (!isset($units[$weightUnit]) || strtolower((string) ($order['dimension_unit'] ?? '')) !== 'cm') {
            throw new \RuntimeException('Unsupported product measurement units.');
        }
        $items = $order['items'] ?? null;
        $default = $this->config['package'] ?? null;
        if (!is_array($items) || $items === [] || !is_array($default)) {
            throw new \RuntimeException('Package data is missing.');
        }
        foreach (['weight', 'width', 'height', 'length'] as $key) {
            if (!isset($default[$key]) || !is_numeric($default[$key]) || (float) $default[$key] <= 0) {
                throw new \RuntimeException('Default package is invalid.');
            }
        }
        $count = 0;
        $weight = 0.0;
        $single = null;
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['quantity']) || !is_int($item['quantity']) || $item['quantity'] <= 0) {
                throw new \RuntimeException('Product quantity is invalid.');
            }
            $count += $item['quantity'];
            if ($count > 1000) {
                throw new \RuntimeException('Product quantity is too large.');
            }
            $itemWeight = (float) ($item['weight'] ?? 0);
            $weight += $item['quantity'] * ($itemWeight > 0 ? $itemWeight * $units[$weightUnit] : (float) $default['weight']);
            $single = $item;
        }
        $package = ['weight' => $weight];
        foreach (['width', 'height', 'length'] as $key) {
            $value = (float) ($single[$key] ?? 0);
            $package[$key] = $count === 1 && $value > 0 ? $value : (float) $default[$key];
        }
        return $package;
    }

    /** Finds new orders and retries preparation errors. Returns counters only. */
    public function run(int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $ids = $this->shop->candidates($this->config['since'], $this->config['eligible_states']);
        $now = gmdate('Y-m-d H:i:s');
        foreach ($ids as $id) {
            $this->db->table('packlink_shipments')->insertOrIgnore([
                'order_id' => $id, 'status' => 'pending', 'attempt_count' => 0,
                'next_attempt_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $jobs = $this->db->table('packlink_shipments')->where('status', 'pending')
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now);
            })->orderBy('id')->limit($limit)->get(['order_id', 'attempt_count']);
        $counts = ['discovered' => count($ids), 'created' => 0, 'deferred' => 0, 'unknown' => 0];
        foreach ($jobs as $job) {
            $failure = 'shop_read_failed';
            try {
                $order = $this->shop->order((int) $job->order_id);
                $failure = 'order_ineligible';
                if (!isset($order['date_add']) || !is_string($order['date_add'])
                    || $order['date_add'] < $this->config['since'] || !isset($order['current_state'])
                    || !in_array($order['current_state'], $this->config['eligible_states'], true)
                    || ($order['is_virtual'] ?? true) !== false) {
                    throw new \RuntimeException('Order is not eligible.');
                }
                $failure = 'carrier_mapping_missing';
                $carrier = $this->config['carriers'][(string) ($order['carrier_reference'] ?? '')] ?? null;
                if (!is_array($carrier) || empty($carrier['service_id'])) {
                    throw new \RuntimeException('Carrier mapping is missing.');
                }
                if (($carrier['pickup'] ?? false) === true) {
                    $failure = 'pickup_unavailable';
                    throw new \RuntimeException('Pickup checkout selection is not available.');
                }
                $failure = 'draft_invalid';
                $config = array_merge($this->config['draft'] ?? [], ['service_id' => $carrier['service_id']]);
                $data = [
                    'to' => $order['to'] ?? null, 'packages' => [$this->package($order)],
                    'content' => $this->config['content'] ?? '', 'content_value' => $order['content_value'] ?? null,
                    'currency' => $order['currency'] ?? null,
                ];
                $draft = $this->factory->build((int) $job->order_id, $data, $config);
                $seller = $order['label_seller_id'] ?? null;
                $failure = 'creation_storage_failed';
                $result = $this->creator->create((int) $job->order_id, $draft, is_int($seller) && $seller > 0 ? $seller : null);
                if ($result === 'created') {
                    ++$counts['created'];
                } elseif ($result === 'unknown' || $result === 'creating') {
                    ++$counts['unknown'];
                }
            } catch (\Throwable $exception) {
                $attempt = min(20, (int) $job->attempt_count + 1);
                $seconds = min(21600, 60 * (2 ** min($attempt - 1, 8)));
                $this->db->table('packlink_shipments')->where('order_id', (int) $job->order_id)->where('status', 'pending')
                    ->update(['attempt_count' => $attempt, 'last_error' => $failure,
                        'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + $seconds), 'updated_at' => gmdate('Y-m-d H:i:s')]);
                ++$counts['deferred'];
            }
        }
        return $counts;
    }
}
