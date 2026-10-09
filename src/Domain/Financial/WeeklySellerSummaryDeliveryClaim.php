<?php

declare(strict_types=1);

namespace PS\Webservice\Domain\Financial;

/** A successfully reserved delivery attempt. Never contains recipient PII. */
final class WeeklySellerSummaryDeliveryClaim
{
    public function __construct(
        public readonly int $deliveryId,
        public readonly int $attempt,
    ) {
    }
}
