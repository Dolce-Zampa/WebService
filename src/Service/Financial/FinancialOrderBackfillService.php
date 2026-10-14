<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Financial;

use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager;
use PS\Webservice\Domain\Financial\FinancialMovement;

/** Imports already-paid PrestaShop orders once, without inventing provider fees or shipping costs. */
final class FinancialOrderBackfillService
{
    public function __construct(private readonly Manager $db, private readonly FinancialLedgerService $ledger)
    {
    }

    /** @return array{eligible: int, created: int, skipped: int} */
    public function import(?DateTimeImmutable $from, ?DateTimeImmutable $to, int $limit, bool $dryRun): array
    {
        $query = $this->db->table('orders as orders')
            ->join('currency as currency', 'currency.id_currency', '=', 'orders.id_currency')
            ->select(['orders.id_order', 'orders.reference', 'orders.id_customer', 'orders.total_paid_tax_incl', 'orders.date_add', 'currency.iso_code'])
            ->where('orders.valid', 1)
            ->where('orders.total_paid_tax_incl', '>', 0)
            ->orderBy('orders.id_order');
        if ($from !== null) $query->where('orders.date_add', '>=', $from->format('Y-m-d H:i:s'));
        if ($to !== null) $query->where('orders.date_add', '<', $to->format('Y-m-d H:i:s'));
        if ($limit > 0) $query->limit($limit);

        $orders = $query->get();
        $created = 0; $skipped = 0;
        foreach ($orders as $order) {
            $orderId = (int) $order->id_order;
            if ($dryRun) { $created++; continue; }
            $result = $this->ledger->record(FinancialMovement::fromArray([
                'type' => 'payment', 'status' => 'paid', 'amount' => (string) $order->total_paid_tax_incl,
                'currency' => strtoupper((string) $order->iso_code), 'occurred_at' => new DateTimeImmutable((string) $order->date_add),
                'order_id' => $orderId, 'customer_id' => (int) $order->id_customer > 0 ? (int) $order->id_customer : null,
                'order_reference' => $order->reference ?: null, 'provider' => 'prestashop-backfill',
                'provider_event_id' => 'order-payment-' . $orderId, 'source_event_type' => 'prestashop.order.backfill',
                'source_event_id' => (string) $orderId, 'metadata' => ['historical_import' => 'orders_v1'],
            ]), 'prestashop-order-backfill:' . $orderId);
            $result->created ? $created++ : $skipped++;
        }
        return ['eligible' => $orders->count(), 'created' => $created, 'skipped' => $skipped];
    }
}
