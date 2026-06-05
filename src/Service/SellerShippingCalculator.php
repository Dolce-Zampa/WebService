<?php
declare(strict_types=1);

namespace PS\Webservice\Service;

final class SellerShippingCalculator
{
    /**
     * @param array<int, array<string, mixed>> $products
     * @param array<int, array<string, mixed>> $rules
     * @return array<int, array<string, int|float|bool>>
     */
    public function calculate(array $products, array $rules): array
    {
        $rulesBySeller = [];
        foreach ($rules as $rule) {
            if (!is_array($rule) || !isset($rule['seller_id'])) {
                continue;
            }

            $sellerId = (int) $rule['seller_id'];
            if ($sellerId <= 0) {
                continue;
            }

            $rulesBySeller[$sellerId] = [
                'free_shipping_threshold' => max(0.0, (float) ($rule['free_shipping_threshold'] ?? 0.0)),
                'shipping_cost' => max(0.0, (float) ($rule['shipping_cost'] ?? 0.0)),
            ];
        }

        $totalsBySeller = [];
        foreach ($products as $product) {
            if (!is_array($product) || !isset($product['seller_id'])) {
                continue;
            }

            $sellerId = (int) $product['seller_id'];
            if ($sellerId <= 0) {
                continue;
            }

            $unitPrice = isset($product['price_wt']) ? (float) $product['price_wt'] : (float) ($product['price'] ?? 0.0);
            $quantity = max(0, (int) ($product['quantity'] ?? 1));
            $lineTotal = $unitPrice * $quantity;
            $totalsBySeller[$sellerId] = ($totalsBySeller[$sellerId] ?? 0.0) + $lineTotal;
        }

        $result = [];
        foreach ($totalsBySeller as $sellerId => $productsTotal) {
            $rule = $rulesBySeller[$sellerId] ?? ['free_shipping_threshold' => 0.0, 'shipping_cost' => 0.0];
            $threshold = (float) $rule['free_shipping_threshold'];
            $defaultShippingCost = (float) $rule['shipping_cost'];
            $isFree = $threshold > 0 && $productsTotal >= $threshold;

            $result[] = [
                'seller_id' => $sellerId,
                'products_total' => round($productsTotal, 2),
                'free_shipping_threshold' => round($threshold, 2),
                'shipping_cost' => $isFree ? 0.0 : round($defaultShippingCost, 2),
                'is_free_shipping' => $isFree,
            ];
        }

        usort($result, static fn(array $a, array $b): int => $a['seller_id'] <=> $b['seller_id']);
        return $result;
    }
}
