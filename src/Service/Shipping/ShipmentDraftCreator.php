<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Shipping;

use Illuminate\Database\ConnectionInterface;

/** Coordinates the irreversible provider call independently of order finalization. */
final class ShipmentDraftCreator
{
    public function __construct(private readonly ConnectionInterface $db, private readonly PacklinkClient $packlink)
    {
    }

    /**
     * Caller supplies a validated, freshly prepared draft. No customer data is stored here.
     * A worker interrupted after claiming the order stays in 'creating': the outcome
     * must be resolved manually because Packlink does not document POST idempotency.
     */
    public function create(int $orderId, array $draft): string
    {
        if ($orderId <= 0) {
            throw new \InvalidArgumentException('Invalid order ID.');
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('packlink_shipments')->insertOrIgnore([
            'order_id' => $orderId, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $claimed = $this->db->table('packlink_shipments')->where('order_id', $orderId)->where('status', 'pending')
            ->update(['status' => 'creating', 'updated_at' => $now]);
        if ($claimed !== 1) {
            return (string) $this->db->table('packlink_shipments')->where('order_id', $orderId)->value('status');
        }
        try {
            $reference = $this->packlink->createDraft($draft);
        } catch (\Throwable $exception) {
            $this->db->table('packlink_shipments')->where('order_id', $orderId)->where('status', 'creating')
                ->update(['status' => 'unknown', 'last_error' => 'create_outcome_unknown', 'updated_at' => gmdate('Y-m-d H:i:s')]);
            return 'unknown';
        }
        // If this write fails the row remains creating; never resend automatically.
        $this->db->table('packlink_shipments')->where('order_id', $orderId)->where('status', 'creating')
            ->update(['status' => 'created', 'provider_reference' => $reference, 'last_error' => null, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        return 'created';
    }
}
