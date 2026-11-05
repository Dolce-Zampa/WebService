<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\Shipping\PacklinkClient;
use PS\Webservice\Service\Shipping\PacklinkException;

require_once __DIR__ . '/../src/Service/Shipping/PacklinkException.php';
require_once __DIR__ . '/../src/Service/Shipping/PacklinkClient.php';

final class PacklinkClientTest extends TestCase
{
    public function testCreatesDraftWithPacklinkContractAndNoRedirects(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(201, [], '{"reference":"IT123"}')]));
        $stack->push(Middleware::history($history));
        $client = new PacklinkClient(new Client(['handler' => $stack]), 'https://api.packlink.com/', 'test-secret');
        self::assertSame('IT123', $client->createDraft(['shipment_custom_reference' => 'shop-1']));
        self::assertSame('https://api.packlink.com/v1/shipments', (string) $history[0]['request']->getUri());
        self::assertSame('test-secret', $history[0]['request']->getHeaderLine('Authorization'));
        self::assertSame(['shipment_custom_reference' => 'shop-1'], json_decode((string) $history[0]['request']->getBody(), true));
        self::assertFalse($history[0]['options']['allow_redirects']);
        self::assertTrue($history[0]['options']['verify']);
    }

    public function testAmbiguousCreateFailureIsSanitizedAndNotRetried(): void
    {
        $history = [];
        $mock = new MockHandler([new ConnectException('secret and private address', new Request('POST', 'https://api.packlink.com/v1/shipments'))]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $client = new PacklinkClient(new Client(['handler' => $stack]), 'https://api.packlink.com', 'secret');
        try {
            $client->createDraft([]);
            self::fail('A communication failure must be reported.');
        } catch (PacklinkException $exception) {
            self::assertSame('create_draft', $exception->operation);
            self::assertStringNotContainsString('secret', $exception->getMessage());
            self::assertCount(1, $history);
        }
    }

    public function testReadingShipmentEncodesReference(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"packlink_reference":"A/B"}')]));
        $stack->push(Middleware::history($history));
        $client = new PacklinkClient(new Client(['handler' => $stack]), 'https://api.packlink.com', 'key');
        self::assertSame('A/B', $client->shipment('A/B')['packlink_reference']);
        self::assertSame('/v1/shipments/A%2FB', $history[0]['request']->getUri()->getPath());
    }

    public function testMissingReferenceIsAnAmbiguousFailure(): void
    {
        $client = new PacklinkClient(new Client(['handler' => new MockHandler([new Response(200, [], '{}')])]), 'https://api.packlink.com', 'key');
        $this->expectException(PacklinkException::class);
        $client->createDraft([]);
    }

    public function testRejectsInsecureUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PacklinkClient(new Client(), 'http://api.packlink.com', 'key');
    }
}
