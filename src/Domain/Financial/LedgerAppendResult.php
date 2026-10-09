<?php

declare(strict_types=1);

namespace PS\Webservice\Domain\Financial;

final class LedgerAppendResult
{
    public function __construct(
        public readonly int $transactionId,
        public readonly int $eventId,
        public readonly bool $created,
    ) {
    }
}
