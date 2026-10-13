<?php

declare(strict_types=1);

namespace PS\Webservice\Domain\Financial;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Immutable input for a financial movement.
 *
 * Amounts deliberately remain decimal strings. Converting an amount received
 * from a payment provider to float before it reaches the ledger would make the
 * audit trail depend on binary floating point rounding.
 */
final class FinancialMovement
{
    public const TYPES = [
        'payment', 'refund', 'commission', 'payout', 'provider_fee', 'chargeback', 'adjustment',
    ];

    public const STATUSES = ['pending', 'available', 'paid', 'reversed', 'failed', 'cancelled'];

    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly string $type,
        public readonly string $status,
        public readonly string $amount,
        public readonly string $currency,
        public readonly DateTimeImmutable $occurredAt,
        public readonly ?int $orderId = null,
        public readonly ?int $artisanId = null,
        public readonly ?int $orderItemId = null,
        public readonly ?string $orderReference = null,
        public readonly ?string $artisanReference = null,
        public readonly ?string $orderItemReference = null,
        public readonly ?string $provider = null,
        public readonly ?string $providerAccountId = null,
        public readonly ?string $providerTransactionId = null,
        public readonly ?string $providerEventId = null,
        public readonly ?string $sourceEventType = null,
        public readonly ?string $sourceEventId = null,
        public readonly ?int $relatedTransactionId = null,
        public readonly array $metadata = [],
        public readonly ?DateTimeImmutable $availableAt = null,
        public readonly ?DateTimeImmutable $settledAt = null,
    ) {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unsupported financial movement type.');
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unsupported financial movement status.');
        }
        if (!preg_match('/^-?\d{1,14}(?:\.\d{1,6})?$/', $amount)) {
            throw new InvalidArgumentException('Amounts must be decimal strings with at most six decimal places.');
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Currency must be an ISO 4217 uppercase three-letter code.');
        }
        if (($provider === null) !== ($providerEventId === null)) {
            throw new InvalidArgumentException('Provider and provider event ID must be supplied together.');
        }
        if (($sourceEventType === null) !== ($sourceEventId === null)) {
            throw new InvalidArgumentException('Source event type and source event ID must be supplied together.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['type', 'status', 'amount', 'currency', 'occurred_at'] as $field) {
            if (!array_key_exists($field, $data)) {
                throw new InvalidArgumentException(sprintf('Missing required financial movement field "%s".', $field));
            }
        }

        if (is_float($data['amount'])) {
            throw new InvalidArgumentException('Amounts must not be passed as floats.');
        }

        return new self(
            type: (string) $data['type'],
            status: (string) $data['status'],
            amount: (string) $data['amount'],
            currency: strtoupper((string) $data['currency']),
            occurredAt: self::date($data['occurred_at']),
            orderId: self::nullableInt($data['order_id'] ?? null),
            artisanId: self::nullableInt($data['artisan_id'] ?? null),
            orderItemId: self::nullableInt($data['order_item_id'] ?? null),
            orderReference: self::nullableString($data['order_reference'] ?? null),
            artisanReference: self::nullableString($data['artisan_reference'] ?? null),
            orderItemReference: self::nullableString($data['order_item_reference'] ?? null),
            provider: self::nullableString($data['provider'] ?? null),
            providerAccountId: self::nullableString($data['provider_account_id'] ?? null),
            providerTransactionId: self::nullableString($data['provider_transaction_id'] ?? null),
            providerEventId: self::nullableString($data['provider_event_id'] ?? null),
            sourceEventType: self::nullableString($data['source_event_type'] ?? null),
            sourceEventId: self::nullableString($data['source_event_id'] ?? null),
            relatedTransactionId: self::nullableInt($data['related_transaction_id'] ?? null),
            metadata: self::metadata($data['metadata'] ?? []),
            availableAt: array_key_exists('available_at', $data) && $data['available_at'] !== null ? self::date($data['available_at']) : null,
            settledAt: array_key_exists('settled_at', $data) && $data['settled_at'] !== null ? self::date($data['settled_at']) : null,
        );
    }

    public function relatedTo(int $transactionId): self
    {
        return new self(
            $this->type, $this->status, $this->amount, $this->currency, $this->occurredAt,
            $this->orderId, $this->artisanId, $this->orderItemId, $this->orderReference,
            $this->artisanReference, $this->orderItemReference, $this->provider,
            $this->providerAccountId, $this->providerTransactionId, $this->providerEventId,
            $this->sourceEventType, $this->sourceEventId, $transactionId, $this->metadata,
            $this->availableAt, $this->settledAt,
        );
    }

    /** @return array<string, mixed> */
    public function toDatabaseValues(): array
    {
        return [
            'order_id' => $this->orderId,
            'artisan_id' => $this->artisanId,
            'order_item_id' => $this->orderItemId,
            'order_reference' => $this->orderReference,
            'artisan_reference' => $this->artisanReference,
            'order_item_reference' => $this->orderItemReference,
            'type' => $this->type,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'provider' => $this->provider,
            'provider_account_id' => $this->providerAccountId,
            'provider_transaction_id' => $this->providerTransactionId,
            'provider_event_id' => $this->providerEventId,
            'source_event_type' => $this->sourceEventType,
            'source_event_id' => $this->sourceEventId,
            'related_transaction_id' => $this->relatedTransactionId,
            'metadata' => $this->metadata === [] ? null : json_encode($this->metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => self::databaseDate($this->occurredAt),
            'available_at' => $this->availableAt === null ? null : self::databaseDate($this->availableAt),
            'settled_at' => $this->settledAt === null ? null : self::databaseDate($this->settledAt),
            'created_at' => self::databaseDate(new DateTimeImmutable()),
            'updated_at' => self::databaseDate(new DateTimeImmutable()),
        ];
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        try {
            return new DateTimeImmutable((string) $value);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('Invalid event timestamp.', 0, $exception);
        }
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new InvalidArgumentException('Financial references must be positive integers.');
        }
        return (int) $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (string) $value;
    }

    /** @param mixed $value @return array<string, mixed> */
    private static function metadata(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('Financial movement metadata must be an array.');
        }
        FinancialMetadata::assertSafe($value);
        return $value;
    }

    private static function databaseDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
