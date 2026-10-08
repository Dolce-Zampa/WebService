<?php

declare(strict_types=1);

namespace PS\Webservice\Domain\Financial;

use InvalidArgumentException;

/**
 * Immutable order-line facts supplied at the instant a commission is earned.
 *
 * Both product totals must already include any discount allocated to this
 * line. Shipping is intentionally not represented here: it is outside the
 * marketplace commission base.
 */
final class CommissionLine
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $orderItemId,
        public readonly int $artisanId,
        public readonly string $currency,
        public readonly string $productTotalTaxIncl,
        public readonly string $productTotalTaxExcl,
        public readonly bool $artisanSubjectToVat,
        public readonly ?string $orderReference = null,
        public readonly ?string $orderItemReference = null,
        public readonly ?string $artisanReference = null,
    ) {
        foreach ([$orderId, $orderItemId, $artisanId] as $reference) {
            if ($reference < 1) {
                throw new InvalidArgumentException('Commission references must be positive integers.');
            }
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('Commission currency must be an ISO 4217 uppercase three-letter code.');
        }
        self::assertAmount($productTotalTaxIncl);
        self::assertAmount($productTotalTaxExcl);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['order_id', 'order_item_id', 'artisan_id', 'currency', 'product_total_tax_incl', 'product_total_tax_excl', 'artisan_subject_to_vat'] as $field) {
            if (!array_key_exists($field, $data)) {
                throw new InvalidArgumentException(sprintf('Missing required commission line field "%s".', $field));
            }
        }
        foreach (['product_total_tax_incl', 'product_total_tax_excl'] as $field) {
            if (is_float($data[$field])) {
                throw new InvalidArgumentException('Commission amounts must not be passed as floats.');
            }
        }

        return new self(
            self::positiveInt($data['order_id']),
            self::positiveInt($data['order_item_id']),
            self::positiveInt($data['artisan_id']),
            strtoupper((string) $data['currency']),
            (string) $data['product_total_tax_incl'],
            (string) $data['product_total_tax_excl'],
            self::boolean($data['artisan_subject_to_vat']),
            self::nullableString($data['order_reference'] ?? null),
            self::nullableString($data['order_item_reference'] ?? null),
            self::nullableString($data['artisan_reference'] ?? null),
        );
    }

    private static function assertAmount(string $amount): void
    {
        if (preg_match('/^\d{1,14}(?:\.\d{1,12})?$/', $amount) !== 1) {
            throw new InvalidArgumentException('Commission amounts must be positive decimal strings with at most twelve decimal places.');
        }
    }

    private static function positiveInt(mixed $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new InvalidArgumentException('Commission references must be positive integers.');
        }
        return (int) $value;
    }

    private static function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return (bool) $value;
        }
        throw new InvalidArgumentException('Artisan VAT status must be a boolean.');
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (string) $value;
    }
}
