<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Shipping;

use Illuminate\Database\ConnectionInterface;

/** Imports the old module's order/reference links after its writer is quiesced. */
final class LegacyPacklinkImporter
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    /**
     * This must run only after every old Packlink worker/hook that creates drafts
     * has been stopped. A malformed or conflicting record aborts the whole import.
     */
    public function import(): int
    {
        return $this->db->transaction(function (): int {
            $rows = $this->db->table('packlink_entity')->where('type', 'OrderShipmentDetails')->orderBy('id')->get(['data']);
            $pairs = [];
            $references = [];
            foreach ($rows as $row) {
                $record = json_decode((string) $row->data, true);
                $order = $record['orderId'] ?? null;
                $reference = $record['reference'] ?? null;
                if (!(is_int($order) || (is_string($order) && ctype_digit($order))) || (int) $order <= 0
                    || !is_string($reference) || trim($reference) === '' || strlen($reference) > 191
                    || preg_match('/[\x00-\x1f\x7f]/', $reference)) {
                    throw new \RuntimeException('Legacy shipment contains an invalid order or reference; import aborted.');
                }
                $orderId = (int) $order;
                if ((isset($pairs[$orderId]) && $pairs[$orderId] !== $reference)
                    || (isset($references[$reference]) && $references[$reference] !== $orderId)) {
                    throw new \RuntimeException('Legacy shipment associations conflict; import aborted.');
                }
                $pairs[$orderId] = $reference;
                $references[$reference] = $orderId;
            }
            $inserted = 0;
            foreach ($pairs as $orderId => $reference) {
                $existing = $this->db->table('packlink_shipments')->where('order_id', $orderId)->first();
                if ($existing !== null) {
                    if ($existing->provider_reference !== $reference) {
                        throw new \RuntimeException('New shipment association conflicts with legacy data; import aborted.');
                    }
                    continue;
                }
                $now = gmdate('Y-m-d H:i:s');
                $this->db->table('packlink_shipments')->insert([
                    'order_id' => $orderId, 'status' => 'created', 'provider_reference' => $reference,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                ++$inserted;
            }
            return $inserted;
        });
    }
}
