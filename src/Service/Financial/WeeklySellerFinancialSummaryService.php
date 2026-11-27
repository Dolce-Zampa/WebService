<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Financial;

use PS\Webservice\Domain\Financial\FinancialWeekPeriod;
use PS\Webservice\Domain\Financial\FinancialMovementKind;
use PS\Webservice\Domain\Financial\WeeklySellerCurrencySummary;
use PS\Webservice\Domain\Financial\WeeklySellerFinancialSummary;
use PS\Webservice\Repositories\FinancialTransactionRepository;

/**
 * Builds a seller-scoped financial report from the immutable ledger.  A
 * movement is counted using its most recently journaled status, so a later
 * reversal cannot be mistaken for a finalized sale.
 */
final class WeeklySellerFinancialSummaryService
{
    private const SCALE = 6;

    public function __construct(private readonly FinancialTransactionRepository $transactions)
    {
    }

    public function summarize(int $artisanId, FinancialWeekPeriod $period): WeeklySellerFinancialSummary
    {
        $movements = $this->transactions->movementsForArtisanPeriod(
            $artisanId,
            $period->startsAtUtc(),
            $period->endsAtUtc(),
        );
        $statuses = $this->transactions->latestStatuses($movements->pluck('id')->map(static fn ($id): int => (int) $id)->all());

        /** @var array<string, array{completed_sales: int, gross_sales: string, commissions: string, refunds: string, completed_payouts: int, completed_payout_amount: string, pending_payouts: int, pending_payout_amount: string}> $totals */
        $totals = [];
        foreach ($movements as $movement) {
            $currency = (string) $movement->currency;
            $totals[$currency] ??= $this->emptyTotals();
            $status = $statuses[(int) $movement->id] ?? (string) $movement->status;
            $amount = (string) $movement->amount;
            $type = FinancialMovementKind::effectiveType($movement);

            if ($movement->type === 'payment' && $this->isFinalized($status)) {
                $totals[$currency]['completed_sales']++;
                $totals[$currency]['gross_sales'] = bcadd($totals[$currency]['gross_sales'], $amount, self::SCALE);
                continue;
            }

            if ($movement->type === 'commission' && $this->isFinalized($status)) {
                $totals[$currency]['commissions'] = bcadd($totals[$currency]['commissions'], $amount, self::SCALE);
                continue;
            }

            if ($movement->type === 'refund' && $this->isFinalized($status)) {
                $totals[$currency]['refunds'] = bcadd($totals[$currency]['refunds'], $this->absolute($amount), self::SCALE);
                continue;
            }

            if ($type === 'transfer' && in_array($status, ['available', 'paid', 'reversed'], true)) {
                if ($movement->source_event_type !== 'stripe.transfer.reversal.created') {
                    $totals[$currency]['completed_transfers']++;
                }
                $totals[$currency]['completed_transfer_amount'] = bcadd($totals[$currency]['completed_transfer_amount'], $amount, self::SCALE);
                continue;
            }

            if ($type === 'payout' && $status === 'paid') {
                $totals[$currency]['completed_payouts']++;
                $totals[$currency]['completed_payout_amount'] = bcadd($totals[$currency]['completed_payout_amount'], $amount, self::SCALE);
                continue;
            }

            if ($type === 'payout' && in_array($status, ['pending', 'available'], true)) {
                $totals[$currency]['pending_payouts']++;
                $totals[$currency]['pending_payout_amount'] = bcadd($totals[$currency]['pending_payout_amount'], $amount, self::SCALE);
            }
        }

        foreach ($this->transactions->connectPayoutsForArtisanPeriod($artisanId, $period->startsAtUtc(), $period->endsAtUtc()) as $payout) {
            $currency = (string) $payout->currency;
            $totals[$currency] ??= $this->emptyTotals();
            $amount = $this->stripeAmount((int) $payout->amount, $currency);
            if ($payout->status === 'paid') {
                $totals[$currency]['completed_payouts']++;
                $totals[$currency]['completed_payout_amount'] = bcadd($totals[$currency]['completed_payout_amount'], $amount, self::SCALE);
            } elseif (in_array($payout->status, ['pending', 'in_transit'], true)) {
                $totals[$currency]['pending_payouts']++;
                $totals[$currency]['pending_payout_amount'] = bcadd($totals[$currency]['pending_payout_amount'], $amount, self::SCALE);
            }
        }

        ksort($totals);
        $currencies = [];
        foreach ($totals as $currency => $total) {
            // A seller's entitlement is a transparent ledger calculation:
            // finalized gross less platform commission and completed refunds.
            $sellerDue = bcsub(
                bcsub($total['gross_sales'], $total['commissions'], self::SCALE),
                $total['refunds'],
                self::SCALE,
            );
            $currencies[] = new WeeklySellerCurrencySummary(
                $currency,
                $total['completed_sales'],
                $total['gross_sales'],
                $total['commissions'],
                $sellerDue,
                $total['completed_payouts'],
                $total['completed_payout_amount'],
                $total['pending_payouts'],
                $total['pending_payout_amount'],
                $total['refunds'],
                $total['completed_transfers'],
                bcsub('0', $total['completed_transfer_amount'], self::SCALE),
            );
        }

        return new WeeklySellerFinancialSummary($artisanId, $period, $currencies);
    }

    /** @return array{completed_sales: int, gross_sales: string, commissions: string, refunds: string, completed_payouts: int, completed_payout_amount: string, pending_payouts: int, pending_payout_amount: string} */
    private function emptyTotals(): array
    {
        return [
            'completed_sales' => 0,
            'gross_sales' => '0.000000',
            'commissions' => '0.000000',
            'refunds' => '0.000000',
            'completed_transfers' => 0,
            'completed_transfer_amount' => '0.000000',
            'completed_payouts' => 0,
            'completed_payout_amount' => '0.000000',
            'pending_payouts' => 0,
            'pending_payout_amount' => '0.000000',
        ];
    }

    private function isFinalized(string $status): bool
    {
        return in_array($status, ['available', 'paid'], true);
    }

    private function absolute(string $amount): string
    {
        return str_starts_with($amount, '-') ? substr($amount, 1) : $amount;
    }

    /** Stripe uses two decimal API units except for its zero-decimal currencies. */
    private function stripeAmount(int $amount, string $currency): string
    {
        $zeroDecimal = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
        return bcdiv((string) $amount, in_array($currency, $zeroDecimal, true) ? '1' : '100', self::SCALE);
    }
}
