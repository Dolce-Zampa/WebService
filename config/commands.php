<?php

/**
 * Registro dei comandi Symfony Console.
 * Inserire qui le FQCN (Fully Qualified Class Name) dei comandi CLI.
 */

return [
    \PS\Webservice\Commands\WarmProductCacheCommand::class,
    \PS\Webservice\Commands\GenerateSitemap::class,
    \PS\Webservice\Commands\SendReviewRequestMailCommand::class,
    \PS\Webservice\Commands\SendWeeklySellerFinancialSummaryCommand::class,
    \PS\Webservice\Commands\ElasticSearch\CreateIndexElk::class,
    \PS\Webservice\Commands\ElasticSearch\IndexBulkProduct::class,
    \PS\Webservice\Commands\ElasticSearch\IndexProduct::class,
];
