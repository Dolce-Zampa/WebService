<?php

// Definizioni di Dependency Injection per i comandi Symfony Console.

$container->set(\PS\Webservice\Service\MailerInterface::class, \DI\get(\PS\Webservice\Service\PS\Mailer::class));

$container->set(\PS\Webservice\Commands\SendReviewRequestMailCommand::class, function ($c) {
    return new \PS\Webservice\Commands\SendReviewRequestMailCommand(
        $c->get(\PS\Webservice\Service\MailerInterface::class),
        $c->get(\PS\Webservice\Service\RedisQueue::class)
    );
});

$container->set(\PS\Webservice\Commands\ElasticSearch\CreateIndexElk::class, function ($c) {
    return new \PS\Webservice\Commands\ElasticSearch\CreateIndexElk(
        $c->get(\Elastic\Elasticsearch\ClientBuilder::class),
        $c->get(\PS\Webservice\Service\ElkService::class),
    );
});

$container->set(\PS\Webservice\Commands\ElasticSearch\IndexBulkProduct::class, function ($c) {
    return new \PS\Webservice\Commands\ElasticSearch\IndexBulkProduct(
        $c->get(\Elastic\Elasticsearch\ClientBuilder::class),
        $c->get(\PS\Webservice\Service\ElkService::class),
        $c->get(\PS\Webservice\Service\RedisQueue::class)
    );
});

$container->set(\PS\Webservice\Commands\ElasticSearch\IndexProduct::class, function ($c) {
    return new \PS\Webservice\Commands\ElasticSearch\IndexProduct(
        $c->get(\Elastic\Elasticsearch\ClientBuilder::class),
        $c->get(\PS\Webservice\Service\ElkService::class),
        $c->get(\PS\Webservice\Service\RedisQueue::class)
    );
});

$container->set(\PS\Webservice\Commands\ElasticSearch\IndexElk::class, function ($c) {
    return new \PS\Webservice\Commands\ElasticSearch\IndexElk(
        $c->get(\Elastic\Elasticsearch\ClientBuilder::class),
        $c->get(\PS\Webservice\Service\ElkService::class),
        $c->get(\PS\Webservice\Service\RedisQueue::class)
    );
});