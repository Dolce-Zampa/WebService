<?php

declare(strict_types=1);

namespace PS\Webservice\Repositories;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\QueryException;
use PS\Webservice\Domain\Financial\FinancialWeekPeriod;
use PS\Webservice\Domain\Financial\WeeklySellerSummaryDeliveryClaim;
use Throwable;

/**
 * Durable delivery ledger for weekly summaries.  It deliberately records no
 * email address, name, report content, or provider response; those are not
 * needed to make retries auditable.
 */
final class WeeklySellerSummaryDeliveryRepository
{
    private const DELIVERIES = 'financial_weekly_summary_deliveries';

    public function __construct(private readonly Manager $db)
    {
    }

    public function claim(int $artisanId, FinancialWeekPeriod $period, DateTimeImmutable $now): ?WeeklySellerSummaryDeliveryClaim
    {
        try {
            return $this->db->getConnection()->transaction(function () use ($artisanId, $period, $now): ?WeeklySellerSummaryDeliveryClaim {
                $delivery = $this->db->table(self::DELIVERIES)
                    ->where('artisan_id', $artisanId)
                    ->where('period_start', $period->startsAtUtc()->format('Y-m-d H:i:s'))
                    ->where('timezone', $period->timezone->getName())
                    ->lockForUpdate()
                    ->first();

                if ($delivery !== null) {
                    if ($delivery->status === 'sent') {
                        return null;
                    }
                    if ($delivery->status === 'sending' && new DateTimeImmutable((string) $delivery->locked_until) > $now) {
                        return null;
                    }

                    $attempt = (int) $delivery->attempts + 1;
                    $this->db->table(self::DELIVERIES)->where('id', $delivery->id)->update([
                        'status' => 'sending',
                        'attempts' => $attempt,
                        'failure_code' => null,
                        'locked_until' => $now->add(new DateInterval('PT15M'))->format('Y-m-d H:i:s'),
                        'updated_at' => $now->format('Y-m-d H:i:s'),
                    ]);

                    return new WeeklySellerSummaryDeliveryClaim((int) $delivery->id, $attempt);
                }

                $id = (int) $this->db->table(self::DELIVERIES)->insertGetId([
                    'artisan_id' => $artisanId,
                    'period_start' => $period->startsAtUtc()->format('Y-m-d H:i:s'),
                    'period_end' => $period->endsAtUtc()->format('Y-m-d H:i:s'),
                    'timezone' => $period->timezone->getName(),
                    'status' => 'sending',
                    'attempts' => 1,
                    'locked_until' => $now->add(new DateInterval('PT15M'))->format('Y-m-d H:i:s'),
                    'created_at' => $now->format('Y-m-d H:i:s'),
                    'updated_at' => $now->format('Y-m-d H:i:s'),
                ]);

                return new WeeklySellerSummaryDeliveryClaim($id, 1);
            });
        } catch (QueryException $exception) {
            // A competing worker may have inserted the unique seller+period
            // row. A second, locked read turns that race into a normal no-op.
            return $this->claimAfterConcurrentInsert($artisanId, $period, $now, $exception);
        }
    }

    public function markSent(WeeklySellerSummaryDeliveryClaim $claim, DateTimeImmutable $now): void
    {
        $this->db->table(self::DELIVERIES)->where('id', $claim->deliveryId)->update([
            'status' => 'sent',
            'sent_at' => $now->format('Y-m-d H:i:s'),
            'locked_until' => null,
            'failure_code' => null,
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    public function markFailed(WeeklySellerSummaryDeliveryClaim $claim, Throwable $exception, DateTimeImmutable $now): void
    {
        $this->db->table(self::DELIVERIES)->where('id', $claim->deliveryId)->update([
            'status' => 'failed',
            'locked_until' => null,
            // Provider exception messages can include recipient addresses or
            // remote payload fragments. The class is enough to trace retries.
            'failure_code' => substr((new \ReflectionClass($exception))->getShortName(), 0, 80),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    private function claimAfterConcurrentInsert(int $artisanId, FinancialWeekPeriod $period, DateTimeImmutable $now, QueryException $previous): ?WeeklySellerSummaryDeliveryClaim
    {
        try {
            return $this->db->getConnection()->transaction(function () use ($artisanId, $period, $now): ?WeeklySellerSummaryDeliveryClaim {
                $delivery = $this->db->table(self::DELIVERIES)
                    ->where('artisan_id', $artisanId)
                    ->where('period_start', $period->startsAtUtc()->format('Y-m-d H:i:s'))
                    ->where('timezone', $period->timezone->getName())
                    ->lockForUpdate()
                    ->first();

                if ($delivery === null || $delivery->status === 'sent' || ($delivery->status === 'sending' && new DateTimeImmutable((string) $delivery->locked_until) > $now)) {
                    return null;
                }

                $attempt = (int) $delivery->attempts + 1;
                $this->db->table(self::DELIVERIES)->where('id', $delivery->id)->update([
                    'status' => 'sending', 'attempts' => $attempt, 'failure_code' => null,
                    'locked_until' => $now->add(new DateInterval('PT15M'))->format('Y-m-d H:i:s'),
                    'updated_at' => $now->format('Y-m-d H:i:s'),
                ]);

                return new WeeklySellerSummaryDeliveryClaim((int) $delivery->id, $attempt);
            });
        } catch (QueryException) {
            // Preserve the original database error for operational diagnosis.
            throw $previous;
        }
    }
}
