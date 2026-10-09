<?php

declare(strict_types=1);

namespace PS\Webservice\Commands\Scheduler;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule(self::NAME)]
final class AppScheduleProvider implements ScheduleProviderInterface
{
    public const NAME = 'default';

    public function getSchedule(): Schedule
    {
        $financialTimezone = (string) env('FINANCIAL_SUMMARY_TIMEZONE', 'UTC');

        return (new Schedule())
            ->add(RecurringMessage::cron('0 3 * * *', new WarmProductCacheMessage(), 'Europe/Rome'))
            ->add(
                // Esegue ogni giorno alla mezzanotte (Europe/Rome)
                RecurringMessage::cron('0 0 * * *', new SendReviewRequestMailMessage())
            )
            ->add(
                RecurringMessage::cron('10 * * * *', new GenerateSitemap())
            )
            ->add(
                // Monday morning: reports always describe the preceding local calendar week.
                RecurringMessage::cron('0 8 * * 1', new SendWeeklySellerFinancialSummaryMessage(), $financialTimezone)
            );
    }
}
