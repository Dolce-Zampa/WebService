<?php

// Definizioni di Dependency Injection per lo scheduler Symfony.

$container->set(\PS\Webservice\Commands\Scheduler\AppScheduleProvider::class, function () {
    return new \PS\Webservice\Commands\Scheduler\AppScheduleProvider();
});

$container->set(\PS\Webservice\Commands\Scheduler\ScheduledPromotionCacheMessageHandler::class, function () {
    return new \PS\Webservice\Commands\Scheduler\ScheduledPromotionCacheMessageHandler(
        new \GuzzleHttp\Client(), (string) env('PS_BASE_URL', ''), (string) env('WEBSERVICE_KEY', '')
    );
});

$container->set(\PS\Webservice\Commands\Scheduler\SendReviewRequestMailMessageHandler::class, function ($c) {
    return new \PS\Webservice\Commands\Scheduler\SendReviewRequestMailMessageHandler(
        $c->get(\PS\Webservice\Commands\SendReviewRequestMailCommand::class)
    );
});
$container->set(\PS\Webservice\Commands\Scheduler\GenerateSitemapHandler::class, function ($c) {
    return new \PS\Webservice\Commands\Scheduler\GenerateSitemapHandler(
        $c->get(\PS\Webservice\Commands\GenerateSitemap::class)
    );
});

$container->set(\PS\Webservice\Commands\Scheduler\SendWeeklySellerFinancialSummaryMessageHandler::class, function ($c) {
    return new \PS\Webservice\Commands\Scheduler\SendWeeklySellerFinancialSummaryMessageHandler(
        $c->get(\PS\Webservice\Commands\SendWeeklySellerFinancialSummaryCommand::class)
    );
});

$container->set(\PS\Webservice\Commands\Scheduler\RetryPendingStripeConnectTransfersMessageHandler::class, function ($c) {
    return new \PS\Webservice\Commands\Scheduler\RetryPendingStripeConnectTransfersMessageHandler(
        $c->get(\PS\Webservice\Commands\RetryPendingStripeConnectTransfersCommand::class)
    );
});

$container->set(\PS\Webservice\Commands\Scheduler\RetryQueuedStripeConnectTransferAllocationsMessageHandler::class, function ($c) {
    return new \PS\Webservice\Commands\Scheduler\RetryQueuedStripeConnectTransferAllocationsMessageHandler(
        $c->get(\PS\Webservice\Commands\RetryQueuedStripeConnectTransferAllocationsCommand::class)
    );
});
