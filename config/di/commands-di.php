<?php

// Definizioni di Dependency Injection per i comandi Symfony Console.

$container->set(\PS\Webservice\Service\MailerInterface::class, \DI\get(\PS\Webservice\Service\PS\Mailer::class));

$container->set(\PS\Webservice\Commands\SendReviewRequestMailCommand::class, function ($c) {
    return new \PS\Webservice\Commands\SendReviewRequestMailCommand(
        $c->get(\PS\Webservice\Service\RedisQueue::class),
        $c->get(\PS\Webservice\Service\PS\PrestashopServiceInterface::class)
    );
});
