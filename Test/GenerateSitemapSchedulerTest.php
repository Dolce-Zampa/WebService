<?php

declare(strict_types=1);

use DI\Container;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Commands\Scheduler\GenerateSitemap;
use PS\Webservice\Commands\Scheduler\GenerateSitemapHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class GenerateSitemapSchedulerTest extends TestCase
{
    public function test_container_handler_sends_the_scheduled_request(): void
    {
        $requests = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200)]));
        $stack->push(Middleware::history($requests));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('Scheduler: eseguito sitemap:generate', self::callback(
            fn ($context) => $context['exitCode'] === 0
        ));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(['log' => $logger]);

        $previous = $_ENV['SITEMAP_CRON_URL'] ?? null;
        $previousServer = $_SERVER['SITEMAP_CRON_URL'] ?? null;
        $_ENV['SITEMAP_CRON_URL'] = $_SERVER['SITEMAP_CRON_URL'] = 'https://example.test/cron?token=test';
        try {
            $container = new Container();
            require __DIR__ . '/../config/di/commands-di.php';
            require __DIR__ . '/../config/di/scheduler-di.php';
            $container->set('sitemap.http', new Client(['handler' => $stack]));
            $container->get(GenerateSitemapHandler::class)(new GenerateSitemap());
        } finally {
            if ($previous === null) {
                unset($_ENV['SITEMAP_CRON_URL']);
            } else {
                $_ENV['SITEMAP_CRON_URL'] = $previous;
            }
            if ($previousServer === null) {
                unset($_SERVER['SITEMAP_CRON_URL']);
            } else {
                $_SERVER['SITEMAP_CRON_URL'] = $previousServer;
            }
        }

        self::assertCount(1, $requests);
        self::assertSame('GET', $requests[0]['request']->getMethod());
        self::assertSame('https://example.test/cron?token=test', (string) $requests[0]['request']->getUri());
    }

    public function test_scheduler_reports_failed_or_incomplete_generation(): void
    {
        foreach ([new Response(403), new Response(200, [], 'Bad token'), new Response(200, ['Refresh' => '5; url=/continue'])] as $response) {
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects(self::once())->method('error')->with('Scheduler: sitemap:generate failed', self::callback(
                fn ($context) => $context['exitCode'] !== 0
            ));
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(['log' => $logger]);
            $http = new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]);
            $handler = new GenerateSitemapHandler(new \PS\Webservice\Commands\GenerateSitemap($http, 'https://example.test/cron'));
            $handler(new GenerateSitemap());
        }
    }

    public function test_missing_url_does_not_send_a_request(): void
    {
        $http = $this->createMock(\GuzzleHttp\ClientInterface::class);
        $http->expects(self::never())->method('request');
        $tester = new CommandTester(new \PS\Webservice\Commands\GenerateSitemap($http, ''));
        self::assertSame(1, $tester->execute([]));
    }
}
