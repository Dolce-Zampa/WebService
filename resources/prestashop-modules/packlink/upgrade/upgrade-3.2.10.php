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
use Packlink\PrestaShop\Classes\Bootstrap;
use Packlink\PrestaShop\Classes\BusinessLogicServices\CleanupTaskSchedulerService;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Updates module to version 3.2.10.
 *
 * @return bool
 * @throws \Logeecom\Infrastructure\ORM\Exceptions\RepositoryNotRegisteredException
 */
function upgrade_module_3_2_10()
{
    $previousShopContext = \Shop::getContext();
    \Shop::setContext(\Shop::CONTEXT_ALL);

    Bootstrap::init();
    CleanupTaskSchedulerService::scheduleTaskCleanupTask();

    \Shop::setContext($previousShopContext);

    return true;
}
