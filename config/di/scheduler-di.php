<?php

// Definizioni di Dependency Injection per lo scheduler Symfony.

$container->set(\PS\Webservice\Commands\Scheduler\AppScheduleProvider::class, function () {
    return new \PS\Webservice\Commands\Scheduler\AppScheduleProvider();
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
