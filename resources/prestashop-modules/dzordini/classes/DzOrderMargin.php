<?php

/** Pure calculations for the administrative report. Money is stored in micro-units. */
final class DzOrderMargin
{
    private static function units($value, $scale)
    {
        $value = trim((string) $value);
        if (!preg_match('/^(\d{1,8})(?:\.(\d{1,' . $scale . '}))?$/D', $value, $match)) {
            throw new InvalidArgumentException('Importo o percentuale non validi.');
        }
        return ((int) $match[1] * (10 ** $scale)) + (int) str_pad($match[2] ?? '', $scale, '0');
    }

    private static function cents($micro)
    {
        return intdiv($micro + 5000, 10000);
    }

    private static function money($cents)
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return $sign . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function feeInput($value)
    {
        $value = str_replace(',', '.', trim((string) $value));
        if ($value === '') {
            return null;
        }
        return self::money(self::units($value, 2));
    }

    /**
     * Current manufacturer rates are indicative, not historical snapshots.
     * Unallocated cart discounts and missing seller facts block the estimate.
     */
    public static function calculate(array $order, array $lines, $fee, $hasRefund)
    {
        $result = ['products' => null, 'artisan' => null, 'commission' => null, 'margin' => null, 'rates' => [], 'note' => []];
        $gross = self::units($order['total_paid_tax_incl'], 6);
        $shipping = self::units($order['total_shipping_tax_incl'], 6);
        $wrapping = self::units($order['total_wrapping_tax_incl'], 6);
        $products = $gross - $shipping - $wrapping;
        if ($products < 0) {
            $result['note'][] = 'Totali ordine incoerenti';
            return $result;
        }
        $result['products'] = self::money(self::cents($products));
        $lineTotal = 0;
        $commission = 0;
        foreach ($lines as $line) {
            $price = self::units($line['total_price_tax_incl'], 6);
            $lineTotal += $price;
            $rate = $line['commission'] ?? null;
            // In this project 0.00 is a legacy sentinel, not a known zero rate.
            if ($rate === null || (float) $rate <= 0 || (float) $rate > 100) {
                $result['note'][] = 'Commissione artigiano assente o da verificare';
                continue;
            }
            $basisPoints = self::units((string) $rate, 2);
            $result['rates'][] = self::money(10000 - $basisPoints) . '%';
            $commission += intdiv($price * $basisPoints, 10000);
        }
        if (!$lines) {
            $result['note'][] = 'Righe prodotto mancanti';
        } elseif (abs(self::cents($lineTotal) - self::cents($products)) > 1) {
            $result['note'][] = 'Sconti o totali prodotto da riconciliare';
        }
        if ($result['note'] === []) {
            $commissionCents = self::cents($commission);
            $artisanCents = self::cents($products) - $commissionCents;
            $result['commission'] = self::money($commissionCents);
            $result['artisan'] = self::money($artisanCents);
            if ($fee !== null && !empty($order['valid']) && !$hasRefund) {
                $result['margin'] = self::money(self::cents($gross) - $artisanCents - self::units($fee, 2));
            }
        }
        if (empty($order['valid'])) $result['note'][] = 'Ordine non valido o non incassato: verificare';
        if ($hasRefund) $result['note'][] = 'Rimborso presente: verificare';
        if ($fee === null) $result['note'][] = 'Costo pagamento non disponibile';
        $result['note'][] = 'Aliquote artigiano correnti, non storicizzate';
        $result['rates'] = array_values(array_unique($result['rates']));
        return $result;
    }

    public static function csvText($value)
    {
        $value = (string) $value;
        return preg_match('/^[\x00-\x20]*[=+\-@\t\r]/u', $value) ? "'" . $value : $value;
    }
}
