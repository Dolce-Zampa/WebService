<?php

declare(strict_types=1);

namespace PS\Webservice\Repositories;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Collection;
use DateTimeInterface;
use PS\Webservice\Domain\Financial\FinancialMovement;
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
            ->whereIn('event_type', ['recorded', 'status_changed'])
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
            ->whereIn('event_type', ['recorded', 'status_changed'])
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
}
