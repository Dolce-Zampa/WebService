<?php
declare(strict_types=1);

namespace PS\Webservice\Service\GiftCard;

/** Plans how seller payouts are funded without treating gift credit as a discount. */
final class GiftCardSellerFunding
{
    /**
     * Each seller is funded in the same proportion as the order. Rounding a
     * charge-funded amount down ensures the sum of transfers linked to a
     * Stripe charge never exceeds that charge. The remainder uses the platform
     * balance, including when there is no Stripe payment at all.
     *
     * @param list<array{manufacturer_id:int,amount:int}> $allocations
     * @return list<array{manufacturer_id:int,charge_cents:int,platform_cents:int}>
     */
    public static function plan(int $totalCents, int $giftCardCents, array $allocations): array
    {
        if ($totalCents < 1 || $totalCents > 2000000000 || $giftCardCents < 0 || $giftCardCents > $totalCents) {
            throw new \InvalidArgumentException('Invalid order payment split.');
        }
        $chargeCents = $totalCents - $giftCardCents;
        $sellerTotal = 0;
        $seen = [];
        $result = [];
        foreach ($allocations as $allocation) {
            $seller = $allocation['manufacturer_id'] ?? null;
            $amount = $allocation['amount'] ?? null;
            if (!is_int($seller) || $seller < 1 || isset($seen[$seller]) || !is_int($amount) || $amount < 0 || $amount > $totalCents) {
                throw new \InvalidArgumentException('Invalid seller allocation.');
            }
            $seen[$seller] = true;
            $sellerTotal += $amount;
            if ($sellerTotal > $totalCents) {
                throw new \InvalidArgumentException('Seller allocations exceed the order total.');
            }
            $fromCharge = intdiv($amount * $chargeCents, $totalCents);
            $result[] = ['manufacturer_id' => $seller, 'charge_cents' => $fromCharge, 'platform_cents' => $amount - $fromCharge];
        }
        return $result;
    }
}
