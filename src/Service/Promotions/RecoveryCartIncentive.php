<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Promotions;

use Illuminate\Database\Capsule\Manager;
use PS\Webservice\Domain\Object\OrderSession;

/** Selects a recovery offer only when its cost is covered by seller commissions. */
final class RecoveryCartIncentive
{
    public function __construct(private readonly Manager $db) {}

    public function message(array $cart, OrderSession $session): string
    {
        if (strtoupper((string) ($cart['currency_iso'] ?? '')) !== 'EUR' || !empty($cart['cart_rules'])) {
            return '';
        }
        $products = $cart['products'] ?? [];
        if (!is_array($products) || $products === []) return '';
        $sellerTotals = [];
        foreach ($products as $product) {
            $seller = filter_var($product['id_manufacturer'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($product['quantity'] ?? null, FILTER_VALIDATE_INT);
            $price = $product['price_wt'] ?? null;
            if (!$seller || $seller < 1 || !$quantity || $quantity < 1 || !is_numeric($price) || !is_finite((float) $price) || (float) $price < 0) return '';
            $sellerTotals[$seller] = ($sellerTotals[$seller] ?? 0) + (int) round((float) $price * 100) * $quantity;
        }
        $productsCents = array_sum($sellerTotals);
        $totalCents = (int) round($session->payableTotal() * 100);
        $shippingCents = $totalCents - $productsCents;
        $cartShipping = $cart['totals']['shipping_tax_incl'] ?? null;
        if ($productsCents <= 0 || $shippingCents < 0 || !is_numeric($cartShipping) || !is_finite((float) $cartShipping) || (float) $cartShipping < 0) return '';
        // A local free-shipping rule can remove the cart's shipping charge.
        if ($shippingCents !== 0 && $shippingCents !== (int) round((float) $cartShipping * 100)) return '';

        $rates = $this->db->table('manufacturer_details')->whereIn('id_manufacturer', array_keys($sellerTotals))->pluck('commission', 'id_manufacturer');
        $commission = 0;
        foreach ($sellerTotals as $seller => $amount) {
            if (!isset($rates[$seller]) || !preg_match('/^\d+(?:\.\d{1,2})?$/D', (string) $rates[$seller])) return '';
            $rate = (float) $rates[$seller];
            // A zero rate is treated as the configured default by checkout.
            if ($rate == 0.0) $rate = (float) env('STRIPE_CONNECT_DEFAULT_COMMISSION_RATE', '20.00');
            if (!is_finite($rate) || $rate <= 0 || $rate > 100) return '';
            $commission += intdiv($amount * (int) round($rate * 100), 10000);
        }
        if ($shippingCents > 0) {
            return $commission >= $shippingCents ? 'Per te la spedizione è gratuita: usa il codice ORD3RFREE nel carrello.' : '';
        }
        // Conservatively treat the whole discount as our cost and account for
        // the reduction in commission after the 5% product discount.
        $discount = (int) ceil($productsCents * 0.05);
        $commissionAfterDiscount = (int) floor($commission * 0.95);
        return $commissionAfterDiscount >= $discount ? 'Per te il 5% di sconto: usa il codice 5ORD3R nel carrello.' : '';
    }
}
