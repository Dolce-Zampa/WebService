<?php

declare(strict_types=1);

namespace PS\Webservice\Domain\Financial;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** A previous calendar week, with explicit reporting timezone and UTC bounds. */
final class FinancialWeekPeriod
{
    public function __construct(
        public readonly DateTimeImmutable $startsAt,
        public readonly DateTimeImmutable $endsAt,
        public readonly DateTimeZone $timezone,
    ) {
        if ($startsAt >= $endsAt) {
            throw new InvalidArgumentException('A financial reporting period must have a positive duration.');
        }
    }

    public static function precedingWeek(DateTimeImmutable $reference, DateTimeZone $timezone): self
    {
        $localReference = $reference->setTimezone($timezone);
        $currentWeekStart = $localReference->setTime(0, 0)->modify('monday this week');
        $previousWeekStart = $currentWeekStart->modify('-7 days');

        return new self($previousWeekStart, $currentWeekStart, $timezone);
    }

    public function startsAtUtc(): DateTimeImmutable
    {
        return $this->startsAt->setTimezone(new DateTimeZone('UTC'));
    }

    public function endsAtUtc(): DateTimeImmutable
    {
        return $this->endsAt->setTimezone(new DateTimeZone('UTC'));
    }

    /** Stable, non-sensitive key used for delivery idempotency. */
    public function key(): string
    {
        return $this->startsAt->format('Y-m-d') . ':' . $this->timezone->getName();
    }
}
