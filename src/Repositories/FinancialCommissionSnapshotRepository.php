<?php

declare(strict_types=1);

namespace PS\Webservice\Repositories;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Collection;
use PS\Webservice\Domain\Financial\CommissionSnapshot;
use stdClass;

/** Insert-only persistence boundary for commission calculation snapshots. */
final class FinancialCommissionSnapshotRepository
{
    private const SNAPSHOTS = 'financial_commission_snapshots';

    public function __construct(private readonly Manager $db)
    {
    }

    /** @param callable(): mixed $callback */
    public function transaction(callable $callback): mixed
    {
        return $this->db->getConnection()->transaction($callback);
    }

    public function append(CommissionSnapshot $snapshot): int
    {
        return (int) $this->db->table(self::SNAPSHOTS)->insertGetId($snapshot->toDatabaseValues());
    }

    public function findByOrderItem(int $orderId, int $orderItemId): ?stdClass
    {
        return $this->db->table(self::SNAPSHOTS)
            ->where('order_id', $orderId)
            ->where('order_item_id', $orderItemId)
            ->first();
    }

    /** @return Collection<int, stdClass> */
    public function forOrder(int $orderId): Collection
    {
        return $this->db->table(self::SNAPSHOTS)
            ->where('order_id', $orderId)
            ->orderBy('order_item_id')
            ->get();
    }
}
