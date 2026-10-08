<?php

declare(strict_types=1);

namespace PS\Webservice\Domain\Financial;

/** A fully reproducible commission result for one order item. */
final class CommissionCalculation
{
    public function __construct(
        public readonly CommissionLine $line,
        public readonly string $ruleVersion,
        public readonly string $commissionRate,
        public readonly string $baseBasis,
        public readonly string $baseAmount,
        public readonly string $commissionAmount,
        public readonly string $artisanAmount,
        public readonly string $roundingMode,
    ) {
    }
}
