<?php

declare(strict_types=1);

namespace PS\Webservice\Repositories;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use DateTimeInterface;
use PS\Webservice\Domain\Financial\FinancialMovement;
use PS\Webservice\Domain\Financial\FinancialMovementKind;
use stdClass;

/**
 * Persistence boundary for the financial ledger.
 *
 * This repository has intentionally no update or delete operation: financial
 * facts and their lifecycle are represented by inserts into the two journal
 * tables only.
 */
final class FinancialTransactionRepository
{
    // Capsule applies PS_TABLE_PREFIX from config/database.php to these logical names.
    // Phinx applies that same prefix while creating the physical tables.
    private const TRANSACTIONS = 'financial_transactions';
    private const EVENTS = 'financial_transaction_events';
    private const STATUS_EVENT_TYPES = ['recorded', 'reversal', 'correction', 'status_changed'];

    public function __construct(private readonly Manager $db)
    {
    }

    /** @param callable(): mixed $callback */
    public function transaction(callable $callback): mixed
    {
        return $this->db->getConnection()->transaction($callback);
    }

    public function appendMovement(FinancialMovement $movement): int
    {
        return (int) $this->db->table(self::TRANSACTIONS)->insertGetId($movement->toDatabaseValues());
    }

    /** @param array<string, mixed> $event */
    public function appendEvent(array $event): int
    {
        return (int) $this->db->table(self::EVENTS)->insertGetId($event);
    }

    public function findEventByIdempotencyKey(string $idempotencyKey): ?stdClass
    {
        return $this->db->table(self::EVENTS)->where('idempotency_key', $idempotencyKey)->first();
    }

    public function findFirstEventForTransaction(int $transactionId): ?stdClass
    {
        return $this->db->table(self::EVENTS)
            ->where('financial_transaction_id', $transactionId)
            ->orderBy('id')
            ->first();
    }

    public function findByProviderEvent(string $provider, string $providerEventId): ?stdClass
    {
        return $this->db->table(self::TRANSACTIONS)
            ->where('provider', $provider)
            ->where('provider_event_id', $providerEventId)
            ->first();
    }

    /**
     * A provider transaction (Stripe PaymentIntent, for example) can emit
     * several webhooks. The original provider event is deliberately kept on
     * the immutable movement, while later webhooks are journal events.
     */
    public function findByProviderTransaction(string $provider, string $providerTransactionId): ?stdClass
    {
        return $this->db->table(self::TRANSACTIONS)
            ->where('provider', $provider)
            ->where('provider_transaction_id', $providerTransactionId)
            ->orderBy('id')
            ->first();
    }

    /**
     * A refund also has a Stripe transaction id, so resolving the source of a
     * reversal must be restricted to the customer payment rather than merely
     * the first movement with a matching provider id.
     */
    public function findPaymentByProviderTransaction(string $provider, string $providerTransactionId): ?stdClass
    {
        return $this->db->table(self::TRANSACTIONS)
            ->where('provider', $provider)
            ->where('provider_transaction_id', $providerTransactionId)
            ->where('type', 'payment')
            ->orderBy('id')
            ->first();
    }

    /**
     * Some Checkout Session webhooks are emitted before Stripe has assigned a
     * PaymentIntent. The session id lives in non-sensitive reconciliation
     * metadata, so it is also a safe lifecycle correlation key.
     */
    public function findByProviderSession(string $provider, string $providerSessionId): ?stdClass
    {
        return $this->db->table(self::TRANSACTIONS)
            ->where('provider', $provider)
            ->whereJsonContains('metadata->stripe_session_id', $providerSessionId)
            ->orderBy('id')
            ->first();
    }

    public function findTransaction(int $transactionId): ?stdClass
    {
        return $this->db->table(self::TRANSACTIONS)->where('id', $transactionId)->first();
    }

    /** Locks the immutable movement row before an event is appended to it. */
    public function findTransactionForUpdate(int $transactionId): ?stdClass
    {
        return $this->db->table(self::TRANSACTIONS)
            ->where('id', $transactionId)
            ->lockForUpdate()
            ->first();
    }

    public function latestStatusEventForUpdate(int $transactionId): ?stdClass
    {
        return $this->db->table(self::EVENTS)
            ->where('financial_transaction_id', $transactionId)
            ->whereIn('event_type', self::STATUS_EVENT_TYPES)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    /** @return Collection<int, stdClass> */
    public function chronologicalForOrder(int $orderId): Collection
    {
        return $this->db->table(self::TRANSACTIONS)
            ->where('order_id', $orderId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{items: Collection<int, stdClass>, total: int}
     */
    public function paginatedChronologicalForOrder(int $orderId, int $page, int $perPage): array
    {
        $query = $this->db->table(self::TRANSACTIONS)->where('order_id', $orderId);
        $total = (int) $query->count();

        return [
            'items' => $query->orderBy('occurred_at')->orderBy('id')
                ->forPage($page, $perPage)
                ->get(),
            'total' => $total,
        ];
    }

    /** @return Collection<int, stdClass> */
    public function chronologicalForArtisan(int $artisanId): Collection
    {
        return $this->db->table(self::TRANSACTIONS)
            ->where('artisan_id', $artisanId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Returns only the ledger facts belonging to one seller for a half-open
     * reporting period.  Keeping this predicate in the persistence boundary
     * is intentional: a financial summary must never be assembled from an
     * unscoped list of marketplace movements.
     *
     * @return Collection<int, stdClass>
     */
    public function movementsForArtisanPeriod(int $artisanId, DateTimeInterface $from, DateTimeInterface $until): Collection
    {
        return $this->db->table(self::TRANSACTIONS)
            ->where('artisan_id', $artisanId)
            ->where('occurred_at', '>=', $from->format('Y-m-d H:i:s'))
            ->where('occurred_at', '<', $until->format('Y-m-d H:i:s'))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Locate the first webhook for the current payout state, not the latest
     * metadata update. Reconciliation observations have no transition date.
     */
    public function connectPayoutsForArtisanPeriod(int $artisanId, DateTimeInterface $from, DateTimeInterface $until): Collection
    {
        $events = $this->db->table('stripe_connect_payouts as payout')
            ->join('stripe_connect_payout_events as event', function ($join): void {
                $join->on('event.stripe_account_id', '=', 'payout.stripe_account_id')
                    ->on('event.stripe_payout_id', '=', 'payout.stripe_payout_id');
            })
            ->where('payout.manufacturer_id', $artisanId)
            ->whereIn('payout.status', ['paid', 'pending', 'in_transit'])
            ->whereNotNull('event.stripe_event_id')
            ->where('event.event_created', '<', $until->getTimestamp())
            ->orderBy('event.event_created')->orderBy('event.id')
            ->get(['payout.id', 'payout.amount', 'payout.currency', 'payout.status', 'event.status as event_status', 'event.event_created']);

        $seen = [];
        $result = [];
        foreach ($events as $event) {
            $id = (int) $event->id;
            $matches = $event->status === 'paid' ? $event->event_status === 'paid'
                : in_array($event->event_status, ['pending', 'in_transit'], true);
            if (!$matches || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if ((int) $event->event_created >= $from->getTimestamp()) {
                $result[] = $event;
            }
        }
        return new Collection($result);
    }

    /** Separate from ledger totals: bank payouts can aggregate multiple transfers. */
    public function connectPayoutReportQuery(array $filters): Builder
    {
        $query = $this->db->table('stripe_connect_payouts');
        if (isset($filters['order_id']) || isset($filters['customer_id'])
            || (isset($filters['type']) && $filters['type'] !== 'payout')
            || (isset($filters['provider']) && $filters['provider'] !== 'stripe')) {
            return $query->whereRaw('1 = 0');
        }
        if (isset($filters['from'])) {
            $query->where('status_event_created', '>=', (new \DateTimeImmutable($filters['from'], new \DateTimeZone('UTC')))->getTimestamp());
        }
        if (isset($filters['to'])) {
            $query->where('status_event_created', '<', (new \DateTimeImmutable($filters['to'], new \DateTimeZone('UTC')))->getTimestamp());
        }
        if (isset($filters['artisan_id'])) {
            $query->where('manufacturer_id', $filters['artisan_id']);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status'] === 'cancelled' ? 'canceled' : $filters['status']);
        }
        return $query;
    }

    /** Verified order allocations only; failed or pending bank payouts are not paid. */
    public function paidConnectPayoutAllocationsForOrder(int $orderId): Collection
    {
        $select = static function (Builder $query, bool $manual) use ($orderId): Collection {
            $columns = ['allocation.amount', 'allocation.currency', 'allocation.manufacturer_id', 'allocation.stripe_payout_id', 'allocation.stripe_transfer_id'];
            if ($manual) {
                $columns[] = 'allocation.direction';
            }
            return $query
            ->join('stripe_connect_payouts as payout', function ($join): void {
                $join->on('payout.stripe_account_id', '=', 'allocation.stripe_account_id')
                    ->on('payout.stripe_payout_id', '=', 'allocation.stripe_payout_id');
            })
            ->leftJoin('stripe_connect_transfers as transfer', function ($join): void {
                $join->on('transfer.stripe_account_id', '=', 'allocation.stripe_account_id')
                    ->on('transfer.stripe_transfer_id', '=', 'allocation.stripe_transfer_id');
            })
            ->where('allocation.order_id', $orderId)->where('payout.status', 'paid')
            ->select($columns)
            ->selectSub(static function (Builder $reversals): void {
                $reversals->from('stripe_connect_transfer_reversals as reversal')
                    ->selectRaw('COUNT(*)')->whereColumn('reversal.stripe_connect_transfer_id', 'transfer.id');
            }, 'transfer_reversal_count')
            ->get();
        };
        return $select($this->db->table('stripe_connect_payout_allocations as allocation'), false)
            ->concat($select($this->db->table('stripe_connect_payout_manual_allocations as allocation'), true));
    }

    /**
     * The transaction row is immutable, therefore the effective status is the
     * latest recorded lifecycle event rather than financial_transactions.status.
     *
     * @param list<int> $transactionIds
     * @return array<int, string>
     */
    public function latestStatuses(array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }

        $events = $this->db->table(self::EVENTS)
            ->whereIn('financial_transaction_id', $transactionIds)
            ->whereIn('event_type', self::STATUS_EVENT_TYPES)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        $statuses = [];
        foreach ($events as $event) {
            $transactionId = (int) $event->financial_transaction_id;
            $statuses[$transactionId] ??= (string) $event->status;
        }

        return $statuses;
    }

    /** @return Collection<int, stdClass> */
    public function eventsForTransaction(int $transactionId): Collection
    {
        return $this->db->table(self::EVENTS)
            ->where('financial_transaction_id', $transactionId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Read-only base query for the administrative financial report.
     *
     * The transaction table intentionally keeps the status first received
     * from the provider.  A report must instead use the latest lifecycle
     * event, otherwise a retried webhook could make an available or reversed
     * movement appear pending again.
     *
     * @param array{from?: string, to?: string, artisan_id?: int, order_id?: int, customer_id?: int, status?: string, provider?: string, type?: string} $filters
     */
    public function financialReportQuery(array $filters): Builder
    {
        $query = $this->db->table(self::TRANSACTIONS . ' as financial_transaction')
            ->select([
                'financial_transaction.id', 'financial_transaction.order_id', 'financial_transaction.customer_id', 'financial_transaction.artisan_id',
                'financial_transaction.order_reference', 'financial_transaction.artisan_reference',
                'financial_transaction.type', 'financial_transaction.amount', 'financial_transaction.currency',
                'financial_transaction.provider', 'financial_transaction.source_event_type', 'financial_transaction.occurred_at',
                'financial_transaction.available_at', 'financial_transaction.settled_at',
            ])
            ->selectSub(function (Builder $statusQuery): void {
                $statusQuery->from(self::EVENTS . ' as status_event')
                    ->select('status_event.status')
                    ->whereColumn('status_event.financial_transaction_id', 'financial_transaction.id')
                    ->whereIn('status_event.event_type', self::STATUS_EVENT_TYPES)
                    ->orderByDesc('status_event.occurred_at')
                    ->orderByDesc('status_event.id')
                    ->limit(1);
            }, 'current_status');

        if (isset($filters['from'])) {
            $query->where('financial_transaction.occurred_at', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->where('financial_transaction.occurred_at', '<', $filters['to']);
        }
        foreach (['artisan_id', 'order_id', 'customer_id', 'provider'] as $filter) {
            if (isset($filters[$filter])) {
                $query->where('financial_transaction.' . $filter, $filters[$filter]);
            }
        }
        if (isset($filters['type'])) {
            $type = $filters['type'];
            if ($type === 'transfer') {
                $query->where(function (Builder $query): void {
                    $query->where('financial_transaction.type', 'transfer')
                        ->orWhere(function (Builder $legacy): void {
                            $legacy->where('financial_transaction.type', 'payout')
                                ->where('financial_transaction.provider', 'stripe')
                                ->whereIn('financial_transaction.source_event_type', FinancialMovementKind::LEGACY_TRANSFER_EVENTS);
                        });
                });
            } elseif ($type === 'payout') {
                $query->where('financial_transaction.type', 'payout')
                    ->where(function (Builder $query): void {
                        $query->whereNull('financial_transaction.provider')
                            ->orWhere('financial_transaction.provider', '!=', 'stripe')
                            ->orWhereNull('financial_transaction.source_event_type')
                            ->orWhereNotIn('financial_transaction.source_event_type', FinancialMovementKind::LEGACY_TRANSFER_EVENTS);
                    });
            } else {
                $query->where('financial_transaction.type', $type);
            }
        }
        if (isset($filters['status'])) {
            $status = $filters['status'];
            $query->where(function (Builder $query) use ($status): void {
                $query->where(function (Builder $ordinary) use ($status): void {
                    if ($status === 'paid') {
                        $ordinary->where(function (Builder $nonLegacy): void {
                            $nonLegacy->where('financial_transaction.type', '!=', 'payout')
                                ->orWhereNull('financial_transaction.provider')->orWhere('financial_transaction.provider', '!=', 'stripe')
                                ->orWhereNull('financial_transaction.source_event_type')
                                ->orWhereNotIn('financial_transaction.source_event_type', FinancialMovementKind::LEGACY_TRANSFER_EVENTS);
                        });
                    }
                    $this->whereCurrentStatus($ordinary, $status);
                });
                if ($status === 'available') {
                    $query->orWhere(function (Builder $legacy): void {
                        $legacy->where('financial_transaction.type', 'payout')
                            ->where('financial_transaction.provider', 'stripe')
                            ->whereIn('financial_transaction.source_event_type', FinancialMovementKind::LEGACY_TRANSFER_EVENTS);
                        $this->whereCurrentStatus($legacy, 'paid');
                    });
                }
            });
        }

        return $query;
    }

    private function whereCurrentStatus(Builder $query, string $status): void
    {
            $query->whereExists(function (Builder $statusQuery) use ($status): void {
                $statusQuery->from(self::EVENTS . ' as status_event')
                    ->whereColumn('status_event.financial_transaction_id', 'financial_transaction.id')
                    ->whereIn('status_event.event_type', self::STATUS_EVENT_TYPES)
                    ->where('status_event.status', $status)
                    ->whereIn('status_event.id', function (Builder $latestStatusQuery): void {
                        $latestStatusQuery->from(self::EVENTS . ' as latest_status_event')
                            ->select('latest_status_event.id')
                            ->whereColumn('latest_status_event.financial_transaction_id', 'financial_transaction.id')
                            ->whereIn('latest_status_event.event_type', self::STATUS_EVENT_TYPES)
                            ->orderByDesc('latest_status_event.occurred_at')
                            ->orderByDesc('latest_status_event.id')
                            ->limit(1);
                    });
            });
    }
}
