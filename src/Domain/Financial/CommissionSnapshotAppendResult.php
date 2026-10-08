<?php

declare(strict_types=1);

namespace PS\Webservice\Domain\Financial;

final class CommissionSnapshotAppendResult
{
    public function __construct(
        public readonly int $snapshotId,
        public readonly bool $created,
    ) {
    }
}
