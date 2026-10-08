<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Financial;

use DateTimeImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use PS\Webservice\Domain\Financial\FinancialMovement;
use PS\Webservice\Domain\Financial\LedgerAppendResult;
use PS\Webservice\Repositories\FinancialTransactionRepository;

/**
 * Append-only use cases for financial movements and their lifecycle events.
 *
 * The service does not calculate commissions, reverse amounts, or interpret a
 * provider payload. Callers must supply the movement they received or decided
 * to book. This keeps accounting policy outside this persistence boundary.
 */
final class FinancialLedgerService
{
    /** @var array<string, list<string>> */
    private const ALLOWED_STATUS_TRANSITIONS = [
        'pending' => ['available', 'paid', 'reversed', 'failed', 'cancelled'],
        'available' => ['paid', 'reversed'],
        'paid' => ['reversed'],
        'reversed' => [],
        'failed' => [],
        'cancelled' => [],
    ];

    public function __construct(private readonly FinancialTransactionRepository $repository)
    {
    }

    public function record(FinancialMovement $movement, string $idempotencyKey): LedgerAppendResult
    {
        return $this->appendMovementEvent($movement, 'recorded', $idempotencyKey);
    }

    /**
     * Records a correction as a new adjustment linked to the original movement.
     * The supplied decimal amount is preserved as-is: its sign and magnitude are
     * an accounting decision of the caller, not a formula inferred here.
     */
    public function recordCorrection(int $originalTransactionId, FinancialMovement $correction, string $idempotencyKey): LedgerAppendResult
    {
        if ($correction->type !== 'adjustment') {
            throw new InvalidArgumentException('A correction must be recorded as an adjustment movement.');
        }

        return $this->recordRelated($originalTransactionId, $correction, 'correction', $idempotencyKey);
    }

    /**
     * Records a reversal as a new movement linked to the original one.
     * No amount or movement type is synthesized by this method.
     */
    public function recordReversal(int $originalTransactionId, FinancialMovement $reversal, string $idempotencyKey): LedgerAppendResult
    {
        return $this->recordRelated($originalTransactionId, $reversal, 'reversal', $idempotencyKey);
    }

    /**
     * Appends a lifecycle event rather than mutating the movement's status.
     * Database row locking serializes distinct transitions for the same
     * movement; the unique idempotency key makes retried concurrent requests
     * return the event already recorded.
     */
    public function changeStatus(
        int $transactionId,
        string $newStatus,
        string $idempotencyKey,
        DateTimeImmutable $occurredAt,
        ?string $sourceEventType = null,
        ?string $sourceEventId = null,
        array $metadata = [],
    ): LedgerAppendResult {
        $this->assertIdempotencyKey($idempotencyKey);
        $this->assertSourceEvent($sourceEventType, $sourceEventId);
        if (!in_array($newStatus, FinancialMovement::STATUSES, true)) {
            throw new InvalidArgumentException('Unsupported financial movement status.');
        }

        $existing = $this->repository->findEventByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return $this->resultFromEvent($existing, false);
        }

        try {
            return $this->repository->transaction(function () use ($transactionId, $newStatus, $idempotencyKey, $occurredAt, $sourceEventType, $sourceEventId, $metadata): LedgerAppendResult {
                $existing = $this->repository->findEventByIdempotencyKey($idempotencyKey);
                if ($existing !== null) {
                    return $this->resultFromEvent($existing, false);
                }

                if ($this->repository->findTransactionForUpdate($transactionId) === null) {
                    throw new DomainException('Cannot change the status of an unknown financial movement.');
                }
                $latestEvent = $this->repository->latestStatusEventForUpdate($transactionId);
                if ($latestEvent === null || !in_array($newStatus, self::ALLOWED_STATUS_TRANSITIONS[$latestEvent->status] ?? [], true)) {
                    throw new DomainException('The requested financial status transition is not allowed.');
                }

                $eventId = $this->repository->appendEvent($this->eventValues(
                    $transactionId, 'status_changed', $newStatus, $idempotencyKey, $occurredAt,
                    $sourceEventType, $sourceEventId, $metadata,
                ));

                return new LedgerAppendResult($transactionId, $eventId, true);
            });
        } catch (QueryException $exception) {
            return $this->recoverDuplicateEvent($idempotencyKey, $exception);
        }
    }

    private function recordRelated(int $originalTransactionId, FinancialMovement $movement, string $eventType, string $idempotencyKey): LedgerAppendResult
    {
        return $this->repository->transaction(function () use ($originalTransactionId, $movement, $eventType, $idempotencyKey): LedgerAppendResult {
            if ($this->repository->findTransactionForUpdate($originalTransactionId) === null) {
                throw new DomainException('Cannot link an event to an unknown financial movement.');
            }
            return $this->appendMovementEvent($movement->relatedTo($originalTransactionId), $eventType, $idempotencyKey);
        });
    }

    private function appendMovementEvent(FinancialMovement $movement, string $eventType, string $idempotencyKey): LedgerAppendResult
    {
        $this->assertIdempotencyKey($idempotencyKey);
        $existing = $this->repository->findEventByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return $this->resultFromEvent($existing, false);
        }

        try {
            return $this->repository->transaction(function () use ($movement, $eventType, $idempotencyKey): LedgerAppendResult {
                $existing = $this->repository->findEventByIdempotencyKey($idempotencyKey);
                if ($existing !== null) {
                    return $this->resultFromEvent($existing, false);
                }

                $transactionId = $this->repository->appendMovement($movement);
                $eventId = $this->repository->appendEvent($this->eventValues(
                    $transactionId, $eventType, $movement->status, $idempotencyKey,
                    $movement->occurredAt, $movement->sourceEventType, $movement->sourceEventId,
                    $movement->metadata,
                ));

                return new LedgerAppendResult($transactionId, $eventId, true);
            });
        } catch (QueryException $exception) {
            $existing = $this->repository->findEventByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                return $this->resultFromEvent($existing, false);
            }

            if ($movement->provider !== null && $movement->providerEventId !== null) {
                $existingMovement = $this->repository->findByProviderEvent($movement->provider, $movement->providerEventId);
                if ($existingMovement !== null) {
                    $event = $this->repository->findFirstEventForTransaction((int) $existingMovement->id);
                    if ($event !== null) {
                        return $this->resultFromEvent($event, false);
                    }
                }
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $metadata @return array<string, mixed> */
    private function eventValues(
        int $transactionId,
        string $eventType,
        string $status,
        string $idempotencyKey,
        DateTimeImmutable $occurredAt,
        ?string $sourceEventType,
        ?string $sourceEventId,
        array $metadata,
    ): array {
        return [
            'financial_transaction_id' => $transactionId,
            'event_type' => $eventType,
            'status' => $status,
            'idempotency_key' => $idempotencyKey,
            'source_event_type' => $sourceEventType,
            'source_event_id' => $sourceEventId,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => $occurredAt->format('Y-m-d H:i:s'),
            'received_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];
    }

    private function assertIdempotencyKey(string $idempotencyKey): void
    {
        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 191) {
            throw new InvalidArgumentException('An idempotency key of at most 191 characters is required.');
        }
    }

    private function assertSourceEvent(?string $sourceEventType, ?string $sourceEventId): void
    {
        if (($sourceEventType === null) !== ($sourceEventId === null)) {
            throw new InvalidArgumentException('Source event type and source event ID must be supplied together.');
        }
    }

    private function resultFromEvent(object $event, bool $created): LedgerAppendResult
    {
        return new LedgerAppendResult((int) $event->financial_transaction_id, (int) $event->id, $created);
    }

    private function recoverDuplicateEvent(string $idempotencyKey, QueryException $exception): LedgerAppendResult
    {
        $existing = $this->repository->findEventByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return $this->resultFromEvent($existing, false);
        }
        throw $exception;
    }
}
