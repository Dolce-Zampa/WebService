<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\Shipping\PrestashopShippingClient;

require_once __DIR__ . '/../src/Service/Shipping/PrestashopShippingClient.php';

final class PrestashopShippingClientTest extends TestCase
{
    public function testCandidatesUseAuthenticatedModuleEndpointWithoutKeyInUrl(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"success":true,"data":{"order_ids":[1,2]}}')]));
        $stack->push(Middleware::history($history));
        $client = new PrestashopShippingClient(new Client(['handler' => $stack]), 'https://shop.example', 'secret', str_repeat('s', 32));
        self::assertSame([1, 2], $client->candidates('2026-11-05 12:00:00', [2, 3]));
        self::assertSame('secret', $history[0]['request']->getHeaderLine('X-WS-Key'));
        self::assertStringNotContainsString('secret', (string) $history[0]['request']->getUri());
        self::assertStringContainsString('controller=packlinkshipping', (string) $history[0]['request']->getUri());
    }

    public function testRejectsUntrustedOrderIdInResponse(): void
    {
        $client = new PrestashopShippingClient(new Client(['handler' => new MockHandler([new Response(200, [], '{"success":true,"data":{"order":{"id":2}}}')])]), 'https://shop.example', 'key', str_repeat('s', 32));
        $this->expectException(RuntimeException::class);
        $client->order(1);
    }
}
