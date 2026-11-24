<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PurchaseProtection;

/** Verifies a service line before a payment session is allowed to use it. */
final class ProtectedCartVerifier
{
    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    public static function verify(array $before, array $after, ProtectionQuote $quote, int $serviceProductId): void
    {
        if ($serviceProductId < 1 || (int) ($before['id'] ?? 0) < 1
            || ($before['id'] ?? null) !== ($after['id'] ?? null)
            || ($before['currency_iso'] ?? null) !== 'EUR' || ($after['currency_iso'] ?? null) !== 'EUR'
            || (int) ($before['id_carrier'] ?? 0) !== (int) ($after['id_carrier'] ?? 0)) {
            throw new \InvalidArgumentException('Purchase protection cart context changed.');
        }
        $beforeTotals = $before['totals'] ?? [];
        $afterTotals = $after['totals'] ?? [];
        $beforeGrand = self::cents($beforeTotals['grand_total_tax_incl'] ?? null);
        $afterGrand = self::cents($afterTotals['grand_total_tax_incl'] ?? null);
        $beforeShipping = self::cents($beforeTotals['shipping_tax_incl'] ?? null);
        $afterShipping = self::cents($afterTotals['shipping_tax_incl'] ?? null);
        if ($beforeGrand - $beforeShipping !== $quote->baseCents || $beforeShipping !== $afterShipping
            || $afterGrand !== $beforeGrand + $quote->priceCents) {
            throw new \InvalidArgumentException('Purchase protection changed the cart totals.');
        }

        $original = self::lines($before['products'] ?? null, $serviceProductId, false);
        $withProtection = self::lines($after['products'] ?? null, $serviceProductId, true);
        if ($original !== $withProtection) {
            throw new \InvalidArgumentException('Purchase protection changed the cart products.');
        }
    }

    /** @return list<array{id:int,attribute:int,customization:int,quantity:int,price:int}> */
    private static function lines(mixed $products, int $serviceProductId, bool $expectService): array
    {
        if (!is_array($products) || $products === []) throw new \InvalidArgumentException('Invalid cart products.');
        $lines = [];
        $serviceCount = 0;
        foreach ($products as $product) {
            if (!is_array($product) || (int) ($product['id_product'] ?? 0) < 1 || (int) ($product['quantity'] ?? 0) < 1) {
                throw new \InvalidArgumentException('Invalid cart product.');
            }
            if ((int) $product['id_product'] === $serviceProductId) {
                if (!$expectService || ++$serviceCount !== 1 || (int) $product['quantity'] !== 1
                    || empty($product['is_virtual']) || !empty($product['id_product_attribute']) || !empty($product['id_customization'])) {
                    throw new \InvalidArgumentException('Invalid protection product.');
                }
                continue;
            }
            $lines[] = ['id' => (int) $product['id_product'], 'attribute' => (int) ($product['id_product_attribute'] ?? 0),
                'customization' => (int) ($product['id_customization'] ?? 0), 'quantity' => (int) $product['quantity'],
                'price' => self::cents($product['price_wt'] ?? null)];
        }
        if ($expectService && $serviceCount !== 1) throw new \InvalidArgumentException('Protection product missing.');
        usort($lines, static fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
        return $lines;
    }

    private static function cents(mixed $value): int
    {
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0
            || (float) $value > intdiv(PHP_INT_MAX, 100)) {
            throw new \InvalidArgumentException('Invalid cart amount.');
        }
        return (int) round((float) $value * 100, 0, PHP_ROUND_HALF_UP);
    }
}
