<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Financial;

use DateTimeImmutable;
use Illuminate\Database\QueryException;
use PS\Webservice\Domain\Financial\CommissionLine;
use PS\Webservice\Domain\Financial\CommissionSnapshot;
use PS\Webservice\Domain\Financial\CommissionSnapshotAppendResult;
use PS\Webservice\Repositories\FinancialCommissionSnapshotRepository;

/**
 * Records each calculation once. Existing rows always win over current policy,
 * preventing a later rate or VAT-policy change from rewriting history.
 */
final class CommissionSnapshotService
{
    public function __construct(
        private readonly CommissionCalculator $calculator,
        private readonly FinancialCommissionSnapshotRepository $repository,
    ) {
    }

    public function record(CommissionLine $line, DateTimeImmutable $calculatedAt): CommissionSnapshotAppendResult
    {
        $existing = $this->repository->findByOrderItem($line->orderId, $line->orderItemId);
        if ($existing !== null) {
            return new CommissionSnapshotAppendResult((int) $existing->id, false);
        }

        try {
            return $this->repository->transaction(function () use ($line, $calculatedAt): CommissionSnapshotAppendResult {
                $existing = $this->repository->findByOrderItem($line->orderId, $line->orderItemId);
                if ($existing !== null) {
                    return new CommissionSnapshotAppendResult((int) $existing->id, false);
                }

                $snapshot = new CommissionSnapshot($this->calculator->calculate($line), $calculatedAt);
                return new CommissionSnapshotAppendResult($this->repository->append($snapshot), true);
            });
        } catch (QueryException $exception) {
            $existing = $this->repository->findByOrderItem($line->orderId, $line->orderItemId);
            if ($existing !== null) {
                return new CommissionSnapshotAppendResult((int) $existing->id, false);
            }
            throw $exception;
        }
    }
}
