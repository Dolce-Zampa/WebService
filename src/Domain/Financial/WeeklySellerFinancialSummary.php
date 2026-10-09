<?php

declare(strict_types=1);

namespace PS\Webservice\Domain\Financial;

/** @phpstan-type CurrencyRows list<WeeklySellerCurrencySummary> */
final class WeeklySellerFinancialSummary
{
    /** @param CurrencyRows $currencies */
    public function __construct(
        public readonly int $artisanId,
        public readonly FinancialWeekPeriod $period,
        public readonly array $currencies,
    ) {
    }

    public function hasActivity(): bool
    {
        foreach ($this->currencies as $summary) {
            if (
                $summary->completedSales > 0
                || $summary->completedPayouts > 0
                || $summary->pendingPayouts > 0
                || $summary->grossSales !== '0.000000'
                || $summary->commissions !== '0.000000'
                || $summary->sellerDue !== '0.000000'
                || $summary->completedPayoutAmount !== '0.000000'
                || $summary->pendingPayoutAmount !== '0.000000'
                || $summary->refunds !== '0.000000'
            ) {
                return true;
            }
        }

        return false;
    }
}
