<?php

declare(strict_types=1);

namespace PS\Webservice\Domain\Financial;

/**
 * Decimal amounts are deliberately strings: this is a display/reporting DTO,
 * not an invitation to turn ledger values into binary floating point values.
 */
final class WeeklySellerCurrencySummary
{
    public function __construct(
        public readonly string $currency,
        public readonly int $completedSales,
        public readonly string $grossSales,
        public readonly string $commissions,
        public readonly string $sellerDue,
        public readonly int $completedPayouts,
        public readonly string $completedPayoutAmount,
        public readonly int $pendingPayouts,
        public readonly string $pendingPayoutAmount,
        public readonly string $refunds,
    ) {
    }
}
