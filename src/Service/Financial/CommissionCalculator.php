<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Financial;

use PS\Webservice\Domain\Financial\CommissionCalculation;
use PS\Webservice\Domain\Financial\CommissionLine;

/**
 * Marketplace commission policy as approved for Financial Tracking.
 *
 * The 20% factor is applied to discounted product totals only. A seller
 * subject to VAT uses the tax-exclusive total; all other sellers use the
 * tax-inclusive total. Calculations use BCMath and retain twelve decimal
 * places, so no application-level rounding is performed.
 */
final class CommissionCalculator
{
    public const RULE_VERSION = 'marketplace-commission-v1';
    public const RATE = '0.20';
    public const ROUNDING_MODE = 'none';
    public const SCALE = 12;

    public function calculate(CommissionLine $line): CommissionCalculation
    {
        $baseBasis = $line->artisanSubjectToVat ? 'product_tax_exclusive' : 'product_tax_inclusive';
        $baseAmount = $line->artisanSubjectToVat ? $line->productTotalTaxExcl : $line->productTotalTaxIncl;
        $commission = bcmul($baseAmount, self::RATE, self::SCALE);
        $artisanAmount = bcsub($baseAmount, $commission, self::SCALE);

        return new CommissionCalculation(
            $line,
            self::RULE_VERSION,
            self::RATE,
            $baseBasis,
            $baseAmount,
            $commission,
            $artisanAmount,
            self::ROUNDING_MODE,
        );
    }
}
