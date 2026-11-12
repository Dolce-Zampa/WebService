<?php
declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Commands\Scheduler\AppScheduleProvider;
use PS\Webservice\Commands\Scheduler\GiftCardMaintenanceMessage;
use PS\Webservice\Commands\Scheduler\GiftCardMaintenanceMessageHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\RecurringMessage;

final class GiftCardMaintenanceSchedulerTest extends TestCase
{
    private const URL = 'https://shop.example/index.php?fc=module&module=mlabgiftcard&controller=api';
    private const KEY = 'gc_api_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_job_is_scheduled_every_minute(): void
    {
        $scheduled = (new AppScheduleProvider())->getSchedule()->getRecurringMessages();
        $expectedId = RecurringMessage::cron('* * * * *', new GiftCardMaintenanceMessage())->getId();
        self::assertContains($expectedId, array_map(fn ($message) => $message->getId(), $scheduled));
    }

    public function test_sends_body_credential_and_retries_after_failure(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Gift card maintenance failed; the scheduler will retry.', ['error_code' => 'unauthorized']
        );
        $logger->expects(self::once())->method('info')->with(
            'Gift card maintenance completed.', ['emails_sent' => 2, 'expired_reservations' => 1]
        );
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(['log' => $logger]);

        $requests = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(401, [], '{"success":false,"error":{"code":"unauthorized"}}'),
            new Response(200, [], '{"success":true,"data":{"emails_sent":2,"expired_reservations":1}}'),
        ]));
        $stack->push(Middleware::history($requests));
        $handler = new GiftCardMaintenanceMessageHandler(new Client(['handler' => $stack]), self::URL, self::KEY);
        $handler(new GiftCardMaintenanceMessage());
        $handler(new GiftCardMaintenanceMessage());

        self::assertCount(2, $requests);
        foreach ($requests as $entry) {
            $request = $entry['request'];
            self::assertSame('POST', $request->getMethod());
            self::assertSame(self::URL . '&action=maintenance', (string) $request->getUri());
            self::assertSame(['api_key' => self::KEY], json_decode((string) $request->getBody(), true));
            self::assertFalse($request->hasHeader('Authorization'));
            self::assertFalse($request->hasHeader('Idempotency-Key'));
            self::assertFalse($entry['options']['allow_redirects']);
            self::assertTrue($entry['options']['verify']);
        }
    }

    public function test_missing_configuration_does_not_stop_scheduler(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(['log' => $logger]);
        $http = $this->createMock(\GuzzleHttp\ClientInterface::class);
        $http->expects(self::never())->method('request');
        $handler = new GiftCardMaintenanceMessageHandler($http, '', '');
        $handler(new GiftCardMaintenanceMessage());
        $handler(new GiftCardMaintenanceMessage());
    }
}
