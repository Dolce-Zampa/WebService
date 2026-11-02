<?php
/**
 * 2025 Packlink
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Apache License 2.0
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at this URL:
 * http://www.apache.org/licenses/LICENSE-2.0.txt
 *
 * @author    Packlink <support@packlink.com>
 * @copyright 2025 Packlink Shipping S.L
 * @license   http://www.apache.org/licenses/LICENSE-2.0.txt  Apache License 2.0
 */
namespace Packlink\PrestaShop\Classes\BusinessLogicServices;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Logeecom\Infrastructure\Configuration\Configuration;
use Logeecom\Infrastructure\ORM\RepositoryRegistry;
use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\TaskExecution\QueueItem;
use Packlink\BusinessLogic\Scheduler\Models\HourlySchedule;
use Packlink\BusinessLogic\Scheduler\Models\Schedule;
use Packlink\BusinessLogic\Scheduler\ScheduleCheckTask;
use Packlink\BusinessLogic\Tasks\TaskCleanupTask;

/**
 * Class CleanupTaskSchedulerService
 *
 * @package Packlink\PrestaShop\Classes\BusinessLogicServices
 */
class CleanupTaskSchedulerService
{
    /**
     * Schedules a new task in charge of deleting old schedule check tasks.
     *
     * @return void
     * @throws \Logeecom\Infrastructure\ORM\Exceptions\RepositoryNotRegisteredException
     */
    public static function scheduleTaskCleanupTask()
    {
        $configuration = ServiceRegister::getService(Configuration::CLASS_NAME);
        $scheduleRepository = RepositoryRegistry::getRepository(Schedule::getClassName());

        $schedule = new HourlySchedule(
            new TaskCleanupTask(ScheduleCheckTask::getClassName(), array(QueueItem::COMPLETED), 3600),
            $configuration->getDefaultQueueName()
        );

        $schedule->setMinute(10);
        $schedule->setNextSchedule();
        $scheduleRepository->save($schedule);
    }
}
