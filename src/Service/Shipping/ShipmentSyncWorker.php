<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Shipping;

use Illuminate\Database\ConnectionInterface;

/** Polls current Packlink state, serializing each order's remote update. */
final class ShipmentSyncWorker
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly PacklinkClient $packlink,
        private readonly PrestashopShippingClient $shop,
        private readonly array $states,
        private readonly array $eligibleStates,
        private readonly int $intervalSeconds = 900,
    ) {
        if ($states === [] || $eligibleStates === [] || $intervalSeconds < 60) {
            throw new \InvalidArgumentException('Invalid shipment synchronization configuration.');
        }
        $ranks = [];
        foreach ($states as $status => $mapping) {
            if (!is_string($status) || $status === '' || !is_array($mapping)
                || !isset($mapping['order_state_id'], $mapping['rank']) || !is_int($mapping['order_state_id'])
                || $mapping['order_state_id'] <= 0 || !is_int($mapping['rank']) || $mapping['rank'] < 0
                || (isset($mapping['labels']) && !is_bool($mapping['labels']))) {
                throw new \InvalidArgumentException('Invalid shipment state mapping.');
            }
            if (isset($ranks[$mapping['rank']]) && $ranks[$mapping['rank']] !== $mapping['order_state_id']) {
                throw new \InvalidArgumentException('Equal shipment ranks must map to the same order state.');
            }
            $ranks[$mapping['rank']] = $mapping['order_state_id'];
        }
        foreach ($eligibleStates as $state) {
            if (!is_int($state) || $state <= 0) {
                throw new \InvalidArgumentException('Invalid eligible state.');
            }
        }
    }

    public function run(int $limit = 50): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $rows = $this->db->table('packlink_shipments')->where('status', 'created')->where('sync_state', 'idle')
            ->where(function ($query) use ($now): void {
                $query->whereNull('sync_due_at')->orWhere('sync_due_at', '<=', $now);
            })->orderBy('id')->limit(max(1, min(100, $limit)))->get();
        $result = ['synced' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $claimed = $this->db->table('packlink_shipments')->where('id', $row->id)->where('sync_state', 'idle')
                ->where(function ($query) use ($now): void {
                    $query->whereNull('sync_due_at')->orWhere('sync_due_at', '<=', $now);
                })->update(['sync_state' => 'running', 'updated_at' => $now]);
            if ($claimed !== 1) {
                continue;
            }
            $failure = 'provider_read_failed';
            try {
                $reference = (string) $row->provider_reference;
                $shipment = $this->packlink->shipment($reference);
                $failure = 'state_unmapped';
                $status = $shipment['state'] ?? null;
                if (($shipment['packlink_reference'] ?? null) !== $reference || !is_string($status)
                    || !isset($this->states[$status])) {
                    throw new \RuntimeException('Unknown Packlink shipment state or reference.');
                }
                $mapping = $this->states[$status];
                $failure = 'tracking_invalid';
                $codes = $shipment['trackings'] ?? [];
                if (!is_array($codes) || (isset($codes[0]) && !is_string($codes[0]))) {
                    throw new \RuntimeException('Invalid Packlink tracking data.');
                }
                $url = $shipment['tracking_url'] ?? null;
                if ($url === '') {
                    $url = null;
                }
                $failure = 'labels_read_failed';
                $labels = ($mapping['labels'] ?? false) ? $this->packlink->labels($reference) : null;
                $failure = 'shop_update_failed';
                $this->shop->applySnapshot([
                    'order_id' => (int) $row->order_id, 'provider_reference' => $reference,
                    'provider_state' => $status, 'order_state_id' => $mapping['order_state_id'],
                    'rank' => $mapping['rank'], 'eligible_states' => $this->eligibleStates,
                    'tracking_number' => $codes[0] ?? null, 'tracking_url' => $url, 'labels' => $labels,
                ]);
                $this->db->table('packlink_shipments')->where('id', $row->id)->where('sync_state', 'running')
                    ->update(['sync_state' => 'idle', 'sync_attempt_count' => 0, 'last_sync_error' => null,
                        'last_synced_at' => gmdate('Y-m-d H:i:s'), 'sync_due_at' => gmdate('Y-m-d H:i:s', time() + $this->intervalSeconds),
                        'updated_at' => gmdate('Y-m-d H:i:s')]);
                ++$result['synced'];
            } catch (\Throwable $exception) {
                $attempt = min(20, (int) $row->sync_attempt_count + 1);
                $delay = min(21600, 60 * (2 ** min($attempt - 1, 8)));
                $this->db->table('packlink_shipments')->where('id', $row->id)->where('sync_state', 'running')
                    ->update(['sync_state' => 'idle', 'sync_attempt_count' => $attempt, 'last_sync_error' => $failure,
                        'sync_due_at' => gmdate('Y-m-d H:i:s', time() + $delay), 'updated_at' => gmdate('Y-m-d H:i:s')]);
                ++$result['failed'];
            }
        }
        return $result;
    }
}
