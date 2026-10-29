<?php
declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Commands\Scheduler\ScheduledPromotionCacheMessage;
use PS\Webservice\Commands\Scheduler\ScheduledPromotionCacheMessageHandler;

final class ScheduledPromotionCacheMessageHandlerTest extends TestCase
{
    public function test_scheduler_calls_authenticated_module_endpoint(): void
    {
        $requests = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"success":true,"data":{"products_processed":0}}')]));
        $stack->push(Middleware::history($requests));
        $handler = new ScheduledPromotionCacheMessageHandler(new Client(['handler' => $stack]), 'https://shop.example/', 'secret-key');
        $handler(new ScheduledPromotionCacheMessage());

        $request = $requests[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/index.php', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $query);
        self::assertSame(['fc' => 'module', 'module' => 'webserviceapi', 'controller' => 'scheduledpromotioncache'], $query);
        self::assertSame('secret-key', $request->getHeaderLine('X-WS-Key'));
    }
}
