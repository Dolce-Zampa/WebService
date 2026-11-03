<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PS;

/** Derive a listing offer exclusively from an already cached product snapshot. */
final class ListingPromotion
{
    public static function fromSnapshot(array $product): ?array
    {
        $combinations = $product['associations']['combinations'] ?? [];
        if (!is_array($combinations) || $combinations === []) {
            return null;
        }

        $defaultId = (int) ($product['id_default_combination'] ?? $product['cache_default_attribute'] ?? 0);
        $best = null;
        foreach ($combinations as $combination) {
            if (!is_array($combination) || !is_numeric($combination['original_price'] ?? null)) {
                continue;
            }
            $sale = round((float) $combination['original_price'], 2);
            $regular = $combination['regular_price'] ?? null;
            // PrestaShop can keep a fixed specific price even with reductions
            // disabled. A zero-impact default variant can use the product's
            // regular price as its reference, including for older snapshots.
            $zeroImpactDefault = (int) ($combination['id'] ?? 0) === $defaultId
                && is_numeric($combination['price'] ?? null)
                && (float) $combination['price'] == 0.0
                && (float) ($combination['ecotax'] ?? 0) == 0.0;
            $baseRegular = $product['regular_price'] ?? $product['price'] ?? null;
            if ($zeroImpactDefault && is_numeric($baseRegular)
                && (!is_numeric($regular) || (float) $regular <= $sale)
                && (float) $baseRegular > $sale) {
                $regular = $baseRegular;
            }
            if (!is_numeric($regular)) {
                continue;
            }
            $regular = round((float) $regular, 2);
            if ($sale <= 0 || $regular <= $sale || (int) ($combination['id'] ?? 0) <= 0) {
                continue;
            }
            if ($best === null || $sale < $best['sale_price']) {
                $best = [
                    'combination_id' => (int) $combination['id'],
                    'regular_price' => $regular,
                    'sale_price' => $sale,
                    'discount_percent' => ($regular - $sale) / $regular * 100,
                    'multiple_combinations' => count($combinations) > 1,
                ];
            }
        }
        return $best;
    }
}
