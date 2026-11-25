<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Financial;

use DateTimeInterface;
use PS\Webservice\Domain\Financial\FinancialMovementKind;
use PS\Webservice\Repositories\FinancialCommissionSnapshotRepository;
use PS\Webservice\Repositories\FinancialTransactionRepository;
use stdClass;

/**
 * Read-only financial view of one order.
 *
 * This service does not infer or persist financial facts.  It combines the
 * append-only ledger with the immutable commission snapshots so an operator
 * can see which portion of a payment is not yet explained (for example
 * shipping, a discount or a missing settlement event).
 */
final class FinancialOrderReconciliationService
{
    public function __construct(
        private readonly FinancialTransactionRepository $transactions,
        private readonly FinancialCommissionSnapshotRepository $commissions,
    ) {
    }

    /** @return array<string, mixed> */
    public function forOrder(int $orderId, int $page = 1, int $perPage = 50): array
    {
        if ($orderId < 1 || $page < 1 || $perPage < 1 || $perPage > 100) {
            throw new \InvalidArgumentException('Invalid reconciliation pagination.');
        }

        $movements = $this->transactions->chronologicalForOrder($orderId);
        $snapshots = $this->commissions->forOrder($orderId);
        $timeline = $this->transactions->paginatedChronologicalForOrder($orderId, $page, $perPage);
        $currency = $this->currency($movements->all(), $snapshots->all());

        $payments = $this->sumMovements($movements->all(), 'payment');
        $refunds = $this->sumMovements($movements->all(), 'refund');
        // Ledger signs are from the marketplace perspective: payouts are
        // outgoing (negative). The reconciliation presents paid-to-seller as
        // a positive amount so its residual is intelligible to operators.
        $payouts = DecimalAmount::absolute($this->paidPayoutMovements($movements->all()));
        $transfers = DecimalAmount::absolute($this->sumMovements($movements->all(), 'transfer'));
        $commissionLedger = $this->sumMovements($movements->all(), 'commission');
        $calculatedCommission = $this->sum($snapshots->all(), 'commission_amount');
        $calculatedEntitlement = $this->sum($snapshots->all(), 'artisan_amount');
        $allocations = $this->transactions->paidConnectPayoutAllocationsForOrder($orderId);
        $connectAmount = null;
        $payoutIds = [];
        $invalidatedAllocations = [];
        $byArtisan = [];
        foreach ($snapshots as $snapshot) {
            $artisanId = (int) $snapshot->artisan_id;
            $byArtisan[$artisanId] ??= ['artisan_id' => $artisanId, 'entitlement' => '0', 'attributed_payouts' => null, 'residual_from_attributed' => null];
            $byArtisan[$artisanId]['entitlement'] = DecimalAmount::add($byArtisan[$artisanId]['entitlement'], (string) $snapshot->artisan_amount);
        }
        foreach ($allocations as $allocation) {
            if ((int) $allocation->transfer_reversal_count > 0) {
                $key = $allocation->stripe_payout_id . ':' . $allocation->stripe_transfer_id;
                $invalidatedAllocations[$key] = ['stripe_payout_id' => $allocation->stripe_payout_id,
                    'stripe_transfer_id' => $allocation->stripe_transfer_id, 'reason' => 'transfer_reversal'];
                continue;
            }
            if ($currency !== (string) $allocation->currency) {
                throw new \RuntimeException('Payout allocation currency does not match order currency.');
            }
            $amount = $this->stripeAmount((int) $allocation->amount, (string) $allocation->currency);
            if ((int) ($allocation->direction ?? 1) === -1) {
                $amount = '-' . $amount;
            }
            $connectAmount = DecimalAmount::add($connectAmount ?? '0', $amount);
            $this->addIdentifier($payoutIds, $allocation->stripe_payout_id);
            $artisanId = (int) $allocation->manufacturer_id;
            $byArtisan[$artisanId] ??= ['artisan_id' => $artisanId, 'entitlement' => '0', 'attributed_payouts' => null, 'residual_from_attributed' => null];
            $byArtisan[$artisanId]['attributed_payouts'] = DecimalAmount::add($byArtisan[$artisanId]['attributed_payouts'] ?? '0', $amount);
        }
        foreach ($byArtisan as &$artisan) {
            if ($artisan['attributed_payouts'] !== null) {
                $artisan['residual_from_attributed'] = DecimalAmount::subtract($artisan['entitlement'], $artisan['attributed_payouts']);
            }
        }
        unset($artisan);
        $netCollected = DecimalAmount::add($payments, $refunds);
        $unexplainedCollection = DecimalAmount::subtract(
            $netCollected,
            DecimalAmount::add($calculatedCommission, $calculatedEntitlement),
        );

        return [
            'order_id' => $orderId,
            'currency' => $currency,
            'identifiers' => $this->identifiers($movements->all(), $snapshots->all()),
            'timeline' => [
                'items' => array_map(
                    fn (stdClass $movement): array => $this->timelineItem($movement, $this->transactions->eventsForTransaction((int) $movement->id)->all()),
                    $timeline['items']->all(),
                ),
                'page' => $page,
                'per_page' => $perPage,
                'total' => $timeline['total'],
                'total_pages' => (int) ceil($timeline['total'] / $perPage),
            ],
            'financials' => [
                'collections' => [
                    'payments' => $payments,
                    'refunds' => $refunds,
                    'net_collected' => $netCollected,
                ],
                'commissions' => [
                    'calculated' => $calculatedCommission,
                    'ledger_recorded' => $commissionLedger,
                    'residual' => DecimalAmount::subtract($calculatedCommission, $commissionLedger),
                ],
                'seller_entitlement' => [
                    'calculated' => $calculatedEntitlement,
                    'payouts' => $payouts,
                    'transfers' => $transfers,
                    'residual' => DecimalAmount::subtract($calculatedEntitlement, $payouts),
                    'transfer_residual' => DecimalAmount::subtract($calculatedEntitlement, $transfers),
                    'connect_bank_payouts' => [
                        'amount' => $connectAmount,
                        'status' => $connectAmount === null ? 'not_attributed' : 'attributed_amount',
                        'residual_from_attributed' => $connectAmount === null ? null : DecimalAmount::subtract($calculatedEntitlement, $connectAmount),
                        'provider_transaction_ids' => $payoutIds,
                        'invalidated_allocations' => array_values($invalidatedAllocations),
                    ],
                    'by_artisan' => array_values($byArtisan),
                ],
                // Shipping is intentionally never part of commission snapshots.
                // It becomes a concrete amount only when a carrier settlement
                // is recorded; until then the remainder is shown explicitly.
                'shipping' => ['amount' => null, 'status' => 'not_recorded'],
                'differences' => [
                    'unexplained_collection' => $unexplainedCollection,
                    'payment_refund_residual' => DecimalAmount::subtract($payments, DecimalAmount::absolute($refunds)),
                ],
            ],
        ];
    }

    /** @param list<stdClass> $movements @param list<stdClass> $snapshots */
    private function currency(array $movements, array $snapshots): ?string
    {
        foreach ([$movements, $snapshots] as $records) {
            foreach ($records as $record) {
                if (is_string($record->currency ?? null) && $record->currency !== '') {
                    return $record->currency;
                }
            }
        }

        return null;
    }

    /** @param list<stdClass> $movements @return array<string, list<string|int>> */
    private function identifiers(array $movements, array $snapshots): array
    {
        $identifiers = [
            'order_references' => [], 'transaction_ids' => [], 'provider_transaction_ids' => [],
            'provider_event_ids' => [], 'payout_provider_transaction_ids' => [], 'transfer_provider_transaction_ids' => [],
        ];
        foreach ($movements as $movement) {
            $identifiers['transaction_ids'][] = (int) $movement->id;
            $this->addIdentifier($identifiers['order_references'], $movement->order_reference ?? null);
            $this->addIdentifier($identifiers['provider_transaction_ids'], $movement->provider_transaction_id ?? null);
            $this->addIdentifier($identifiers['provider_event_ids'], $movement->provider_event_id ?? null);
            if (FinancialMovementKind::effectiveType($movement) === 'payout') {
                $this->addIdentifier($identifiers['payout_provider_transaction_ids'], $movement->provider_transaction_id ?? null);
            }
            if (FinancialMovementKind::effectiveType($movement) === 'transfer') {
                $this->addIdentifier($identifiers['transfer_provider_transaction_ids'], $movement->provider_transaction_id ?? null);
            }
        }
        foreach ($snapshots as $snapshot) {
            $this->addIdentifier($identifiers['order_references'], $snapshot->order_reference ?? null);
        }

        return $identifiers;
    }

    /** @param list<string|int> $identifiers */
    private function addIdentifier(array &$identifiers, mixed $value): void
    {
        if (!is_string($value) || $value === '' || in_array($value, $identifiers, true)) {
            return;
        }
        $identifiers[] = $value;
    }

    /** @param list<stdClass> $records */
    private function sum(array $records, string $field): string
    {
        $total = '0';
        foreach ($records as $record) {
            $total = DecimalAmount::add($total, (string) ($record->{$field} ?? '0'));
        }

        return $total;
    }

    /** @param list<stdClass> $movements */
    private function sumMovements(array $movements, string $type): string
    {
        return $this->sum(array_values(array_filter($movements, static fn (stdClass $movement): bool => FinancialMovementKind::effectiveType($movement) === $type)), 'amount');
    }

    /** Sum only payouts actually paid, including linked reversals of paid payouts. */
    private function paidPayoutMovements(array $movements): string
    {
        $payouts = array_values(array_filter($movements, static fn (stdClass $movement): bool => FinancialMovementKind::effectiveType($movement) === 'payout'));
        $paid = [];
        foreach ($payouts as $payout) {
            if ($payout->related_transaction_id !== null) {
                continue;
            }
            $events = $this->transactions->eventsForTransaction((int) $payout->id)->all();
            $statuses = array_values(array_map(static fn (stdClass $event): string => (string) $event->status,
                array_filter($events, static fn (stdClass $event): bool => in_array($event->event_type, ['recorded', 'reversal', 'correction', 'status_changed'], true))));
            if ($statuses === []) {
                $statuses = [(string) $payout->status];
            }
            $current = $statuses[array_key_last($statuses)];
            if ($current === 'paid' || ($current === 'reversed' && in_array('paid', $statuses, true))) {
                $paid[(int) $payout->id] = true;
            }
        }
        $amounts = array_values(array_filter($payouts, static function (stdClass $payout) use ($paid): bool {
            return $payout->related_transaction_id === null
                ? isset($paid[(int) $payout->id])
                : isset($paid[(int) $payout->related_transaction_id]) && $payout->status === 'reversed';
        }));
        return $this->sum($amounts, 'amount');
    }

    private function stripeAmount(int $amount, string $currency): string
    {
        $zeroDecimal = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
        return bcdiv((string) $amount, in_array($currency, $zeroDecimal, true) ? '1' : '100', 6);
    }

    /** @param list<stdClass> $events @return array<string, mixed> */
    private function timelineItem(stdClass $movement, array $events): array
    {
        $statusEvents = array_values(array_filter($events, static fn (stdClass $event): bool => in_array($event->event_type ?? null, ['recorded', 'reversal', 'correction', 'status_changed'], true)));
        $currentStatus = $statusEvents === [] ? $movement->status : $statusEvents[array_key_last($statusEvents)]->status;

        return [
            'id' => (int) $movement->id,
            'type' => FinancialMovementKind::effectiveType($movement),
            'status' => FinancialMovementKind::effectiveStatus($movement, (string) $currentStatus),
            'amount' => (string) $movement->amount,
            'currency' => $movement->currency,
            'occurred_at' => $this->date($movement->occurred_at ?? null),
            'available_at' => $this->date($movement->available_at ?? null),
            'settled_at' => $this->date($movement->settled_at ?? null),
            'related_transaction_id' => $movement->related_transaction_id === null ? null : (int) $movement->related_transaction_id,
            'provider' => $movement->provider,
            'provider_transaction_id' => $movement->provider_transaction_id,
            'provider_event_id' => $movement->provider_event_id,
            'source_event_type' => $movement->source_event_type,
            'source_event_id' => $movement->source_event_id,
            'events' => array_map(fn (stdClass $event): array => [
                'id' => (int) $event->id,
                'type' => $event->event_type,
                'status' => $event->status,
                'occurred_at' => $this->date($event->occurred_at ?? null),
                'source_event_type' => $event->source_event_type,
                'source_event_id' => $event->source_event_id,
            ], $events),
        ];
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : (string) $value;
    }
}

/** Exact signed decimal arithmetic without float conversion or rounding. */
final class DecimalAmount
{
    public static function add(string $left, string $right): string
    {
        [$leftSign, , $scale] = self::parts($left);
        [$rightSign, , $rightScale] = self::parts($right);
        $scale = max($scale, $rightScale);
        $leftMagnitude = self::scaledMagnitude($left, $scale);
        $rightMagnitude = self::scaledMagnitude($right, $scale);

        if ($leftSign === $rightSign) {
            return self::format($leftSign, self::unsignedAdd($leftMagnitude, $rightMagnitude), $scale);
        }
        $comparison = self::compare($leftMagnitude, $rightMagnitude);
        if ($comparison === 0) {
            return '0';
        }
        if ($comparison > 0) {
            return self::format($leftSign, self::unsignedSubtract($leftMagnitude, $rightMagnitude), $scale);
        }
        return self::format($rightSign, self::unsignedSubtract($rightMagnitude, $leftMagnitude), $scale);
    }

    public static function subtract(string $left, string $right): string
    {
        return self::add($left, str_starts_with($right, '-') ? substr($right, 1) : '-' . $right);
    }

    public static function absolute(string $value): string
    {
        return ltrim($value, '-');
    }

    /** @return array{int, string, int} */
    private static function parts(string $value): array
    {
        if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $matches)) {
            throw new \InvalidArgumentException('Invalid decimal amount in financial record.');
        }
        return [$matches[1] === '-' ? -1 : 1, ltrim($matches[2] . ($matches[3] ?? ''), '0') ?: '0', strlen($matches[3] ?? '')];
    }

    private static function scaledMagnitude(string $value, int $scale): string
    {
        [, $magnitude, $currentScale] = self::parts($value);
        return $magnitude . str_repeat('0', $scale - $currentScale);
    }

    private static function compare(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    private static function unsignedAdd(string $left, string $right): string
    {
        $carry = 0; $result = '';
        $length = max(strlen($left), strlen($right));
        $left = str_pad($left, $length, '0', STR_PAD_LEFT);
        $right = str_pad($right, $length, '0', STR_PAD_LEFT);
        for ($i = $length - 1; $i >= 0; --$i) {
            $sum = (int) $left[$i] + (int) $right[$i] + $carry;
            $result = ($sum % 10) . $result; $carry = intdiv($sum, 10);
        }
        return ($carry > 0 ? (string) $carry : '') . $result;
    }

    private static function unsignedSubtract(string $left, string $right): string
    {
        $borrow = 0; $result = '';
        $right = str_pad($right, strlen($left), '0', STR_PAD_LEFT);
        for ($i = strlen($left) - 1; $i >= 0; --$i) {
            $digit = (int) $left[$i] - (int) $right[$i] - $borrow;
            if ($digit < 0) { $digit += 10; $borrow = 1; } else { $borrow = 0; }
            $result = $digit . $result;
        }
        return ltrim($result, '0') ?: '0';
    }

    private static function format(int $sign, string $magnitude, int $scale): string
    {
        $magnitude = ltrim($magnitude, '0') ?: '0';
        if ($scale > 0) {
            $magnitude = str_pad($magnitude, $scale + 1, '0', STR_PAD_LEFT);
            $magnitude = substr($magnitude, 0, -$scale) . '.' . substr($magnitude, -$scale);
            $magnitude = rtrim(rtrim($magnitude, '0'), '.');
        }
        return $magnitude === '0' || $sign > 0 ? $magnitude : '-' . $magnitude;
    }
}
