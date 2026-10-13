<?php

declare(strict_types=1);

namespace PS\Webservice\Domain\Financial;

use DateTimeImmutable;

/** Immutable persisted evidence of a commission calculation. */
final class CommissionSnapshot
{
    public function __construct(
        public readonly CommissionCalculation $calculation,
        public readonly DateTimeImmutable $calculatedAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toDatabaseValues(): array
    {
        $line = $this->calculation->line;

        return [
            'order_id' => $line->orderId,
            'order_item_id' => $line->orderItemId,
            'artisan_id' => $line->artisanId,
            'order_reference' => $line->orderReference,
            'order_item_reference' => $line->orderItemReference,
            'artisan_reference' => $line->artisanReference,
            'currency' => $line->currency,
            'artisan_subject_to_vat' => $line->artisanSubjectToVat,
            'product_total_tax_incl' => $line->productTotalTaxIncl,
            'product_total_tax_excl' => $line->productTotalTaxExcl,
            'commission_rule_version' => $this->calculation->ruleVersion,
            'commission_rate' => $this->calculation->commissionRate,
            'base_basis' => $this->calculation->baseBasis,
            'base_amount' => $this->calculation->baseAmount,
            'commission_amount' => $this->calculation->commissionAmount,
            'artisan_amount' => $this->calculation->artisanAmount,
            'rounding_mode' => $this->calculation->roundingMode,
            'shipping_included' => false,
            'calculated_at' => $this->calculatedAt->format('Y-m-d H:i:s'),
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];
    }
}
