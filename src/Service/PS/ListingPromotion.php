<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PS;

/** Derive a listing offer exclusively from an already cached product snapshot. */
final class ListingPromotion
{
    /** Avoid database lookups when the snapshot cannot need a legacy reference. */
    public static function needsPromotionLookup(array $product): bool
    {
        $pricesByImpact = [];
        foreach ($product['associations']['combinations'] ?? [] as $combination) {
            if (!is_numeric($combination['price'] ?? null) || !is_numeric($combination['original_price'] ?? null)) {
                continue;
            }
            $key = (string) (float) $combination['price'] . ':' . (string) (float) ($combination['ecotax'] ?? 0);
            $pricesByImpact[$key][] = $combination;
        }
        foreach ($pricesByImpact as $combinations) {
            $prices = array_unique(array_map(fn (array $combination) => round((float) $combination['original_price'], 2), $combinations));
            if (count($prices) < 2) {
                continue;
            }
            foreach ($combinations as $combination) {
                if (!is_numeric($combination['regular_price'] ?? null)
                    || (float) $combination['regular_price'] <= (float) $combination['original_price']) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function fromSnapshot(array $product, array $promotions = []): ?array
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
            // Legacy snapshots do not include a regular combination price. For
            // an active variant promotion, an undiscounted variant with the same
            // impact and ecotax provides a tax-inclusive reference price.
            $id = (int) ($combination['id'] ?? 0);
            if ((!is_numeric($regular) || (float) $regular <= $sale)
                && isset($promotions[$id]) && !isset($promotions[0])
                && is_numeric($combination['price'] ?? null)) {
                foreach ($combinations as $reference) {
                    $referenceId = (int) ($reference['id'] ?? 0);
                    if ($referenceId === $id || isset($promotions[$referenceId])
                        || !is_numeric($reference['price'] ?? null)
                        || (float) $reference['price'] !== (float) $combination['price']
                        || (float) ($reference['ecotax'] ?? 0) !== (float) ($combination['ecotax'] ?? 0)
                        || !is_numeric($reference['original_price'] ?? null)) {
                        continue;
                    }
                    $regular = $reference['original_price'];
                    break;
                }
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
