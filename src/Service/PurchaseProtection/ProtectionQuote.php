<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PurchaseProtection;

/** A price calculated in euro cents from a server-validated checkout total. */
final class ProtectionQuote
{
    private function __construct(
        public readonly int $baseCents,
        public readonly int $rateBasisPoints,
        public readonly int $priceCents,
    ) {}

    /** Parse a percentage with at most two decimals, e.g. 5 or 5.25. */
    public static function rateFromPercent(string $percent): int
    {
        $percent = trim($percent);
        if (!preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/D', $percent, $parts)) {
            throw new \InvalidArgumentException('Invalid purchase protection percentage.');
        }
        $basisPoints = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
        if ($basisPoints < 1 || $basisPoints > 10000) {
            throw new \InvalidArgumentException('Invalid purchase protection percentage.');
        }
        return $basisPoints;
    }

    /** @param array<string, mixed> $cart Server-fetched, discounted PrestaShop cart. */
    public static function fromCart(array $cart, ?string $percent = null): self
    {
        if (strtoupper((string) ($cart['currency_iso'] ?? '')) !== 'EUR'
            || empty($cart['products']) || !is_array($cart['products'])
            || !array_filter($cart['products'], static fn ($line) => is_array($line)
                && filter_var($line['id_product'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false)) {
            throw new \InvalidArgumentException('Invalid purchase protection cart.');
        }
        // A virtual item is not shipped. Its share of any order-wide discount
        // must be allocated explicitly before this quote can include mixed carts.
        foreach ($cart['products'] as $product) {
            if (!is_array($product) || !empty($product['is_virtual'])) {
                throw new \InvalidArgumentException('Purchase protection requires physical products.');
            }
        }
        $totals = $cart['totals'] ?? null;
        if (!is_array($totals)) {
            throw new \InvalidArgumentException('Invalid purchase protection cart totals.');
        }
        $grand = self::minorAmount($totals['grand_total_tax_incl'] ?? null);
        $shipping = self::minorAmount($totals['shipping_tax_incl'] ?? null);
        $configured = $_ENV['PURCHASE_PROTECTION_PERCENT'] ?? $_SERVER['PURCHASE_PROTECTION_PERCENT']
            ?? getenv('PURCHASE_PROTECTION_PERCENT');
        $configuredPercent = $percent ?? ($configured === false ? '5' : (string) $configured);
        return self::calculate($grand - $shipping, self::rateFromPercent($configuredPercent));
    }

    private static function minorAmount(mixed $amount): int
    {
        if (!is_numeric($amount) || !is_finite((float) $amount) || (float) $amount < 0
            || (float) $amount > intdiv(PHP_INT_MAX, 100)) {
            throw new \InvalidArgumentException('Invalid purchase protection cart total.');
        }
        return (int) round((float) $amount * 100, 0, PHP_ROUND_HALF_UP);
    }

    /** One basis point is 0.01%; round the resulting amount to the nearest cent. */
    public static function calculate(int $baseCents, int $rateBasisPoints): self
    {
        if ($baseCents < 1 || $rateBasisPoints < 1 || $rateBasisPoints > 10000
            || $baseCents > intdiv(PHP_INT_MAX - 5000, $rateBasisPoints)) {
            throw new \InvalidArgumentException('Invalid purchase protection quote.');
        }

        return new self($baseCents, $rateBasisPoints, intdiv($baseCents * $rateBasisPoints + 5000, 10000));
    }
}
