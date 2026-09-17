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
        return (new Schedule())
            ->add(
                // Esegue ogni giorno alla mezzanotte (Europe/Rome)
                RecurringMessage::cron('0 0 * * *', new SendReviewRequestMailMessage())
            );
    }
}
