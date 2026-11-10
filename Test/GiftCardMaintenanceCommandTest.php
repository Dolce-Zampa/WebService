<?php
declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Commands\GiftCardMaintenanceCommand;
use PS\Webservice\Commands\Scheduler\GiftCardMaintenanceMessageHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class GiftCardMaintenanceCommandTest extends TestCase
{
    private const URL = 'https://shop.example/index.php?fc=module&module=mlabgiftcard&controller=api';
    private const KEY = 'gc_api_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_manual_command_processes_queue_and_reports_counts(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"success":true,"data":{"emails_sent":1,"expired_reservations":2}}'),
        ]));
        $stack->push(Middleware::history($history));
        $handler = new GiftCardMaintenanceMessageHandler(new Client(['handler' => $stack]), self::URL, self::KEY);
        $tester = new CommandTester(new GiftCardMaintenanceCommand($handler));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Email accettate per l’invio: 1', $tester->getDisplay());
        self::assertStringContainsString('Prenotazioni scadute rilasciate: 2', $tester->getDisplay());
        self::assertSame(self::URL . '&action=maintenance', (string) $history[0]['request']->getUri());
        self::assertSame(['api_key' => self::KEY], json_decode((string) $history[0]['request']->getBody(), true));
        self::assertFalse($history[0]['request']->hasHeader('Authorization'));
        self::assertSame(120, $history[0]['options']['timeout']);
        self::assertStringNotContainsString(self::KEY, $tester->getDisplay());
    }

    public function test_missing_configuration_fails_without_a_request(): void
    {
        $http = $this->createMock(\GuzzleHttp\ClientInterface::class);
        $http->expects(self::never())->method('request');
        $tester = new CommandTester(new GiftCardMaintenanceCommand(new GiftCardMaintenanceMessageHandler($http, '', '')));
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('GIFT_CARD_API_URL', $tester->getDisplay());
    }

    public function test_authentication_failure_returns_nonzero_without_secrets(): void
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(401, [], '{"success":false,"error":{"code":"unauthorized","message":"sensitive"}}'),
        ]));
        $handler = new GiftCardMaintenanceMessageHandler(new Client(['handler' => $stack]), self::URL, self::KEY);
        $tester = new CommandTester(new GiftCardMaintenanceCommand($handler));
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('unauthorized', $tester->getDisplay());
        self::assertStringNotContainsString('sensitive', $tester->getDisplay());
        self::assertStringNotContainsString(self::KEY, $tester->getDisplay());
    }

    public function test_malformed_maintenance_response_returns_nonzero(): void
    {
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"success":true,"data":{}}')]));
        $handler = new GiftCardMaintenanceMessageHandler(new Client(['handler' => $stack]), self::URL, self::KEY);
        $tester = new CommandTester(new GiftCardMaintenanceCommand($handler));
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('non valida', $tester->getDisplay());
    }
}
