<?php
declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\ConnectException;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\GiftCard\GiftCardApiException;
use PS\Webservice\Service\GiftCard\GiftCardClient;

final class GiftCardClientTest extends TestCase
{
    private const URL = 'https://shop.example/index.php?fc=module&module=mlabgiftcard&controller=api';
    private const KEY = 'gc_api_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_tls_can_be_disabled_only_with_explicit_development_opt_in(): void
    {
        self::assertFalse(GiftCardClient::verifyTlsForEnvironment('testing', true));
        self::assertFalse(GiftCardClient::verifyTlsForEnvironment('local', 'true'));
        self::assertTrue(GiftCardClient::verifyTlsForEnvironment('testing', false));
        self::assertTrue(GiftCardClient::verifyTlsForEnvironment('production', true));
        self::assertTrue(GiftCardClient::verifyTlsForEnvironment('', true));

        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"success":true,"data":{}}')]));
        $stack->push(Middleware::history($history));
        $client = new GiftCardClient(new Client(['handler' => $stack]), self::URL, self::KEY, false);
        $client->maintenance();
        self::assertFalse($history[0]['options']['verify']);
    }

    public function testReserveSendsCredentialAndCodeOnlyInJsonAndDoesNotFollowRedirects(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"success":true,"data":{"reservation":{"id":"abc"}}}') ]));
        $stack->push(Middleware::history($history));
        $client = new GiftCardClient(new Client(['handler' => $stack]), self::URL, self::KEY);

        $result = $client->post('reserve', ['code' => 'GC-SECRET', 'cart_id' => 22], 'checkout:22:attempt:1');

        self::assertSame('abc', $result['reservation']['id']);
        $request = $history[0]['request'];
        self::assertSame(self::URL . '&action=reserve', (string) $request->getUri());
        self::assertSame('checkout:22:attempt:1', $request->getHeaderLine('Idempotency-Key'));
        self::assertSame(['code' => 'GC-SECRET', 'cart_id' => 22, 'api_key' => self::KEY], json_decode((string) $request->getBody(), true));
        self::assertFalse($history[0]['options']['allow_redirects']);
        self::assertTrue($history[0]['options']['verify']);
    }

    public function testProviderErrorsAreControlledAndDoNotExposeResponseOrSecrets(): void
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(409, [], '{"success":false,"error":{"code":"insufficient_balance","message":"GC-SECRET"}}'),
        ]));
        $client = new GiftCardClient(new Client(['handler' => $stack]), self::URL, self::KEY);

        try {
            $client->post('reserve', ['code' => 'GC-SECRET'], 'checkout:22:attempt:1');
            self::fail('Expected an error.');
        } catch (GiftCardApiException $error) {
            self::assertSame('insufficient_balance', $error->errorCode);
            self::assertSame(409, $error->httpStatus);
            self::assertStringNotContainsString('GC-SECRET', $error->getMessage());
            self::assertStringNotContainsString(self::KEY, $error->getMessage());
        }
    }

    public function testRejectsInsecureEndpointBeforeSendingAnyCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GiftCardClient(new Client(), str_replace('https://', 'http://', self::URL), self::KEY);
    }

    public function testTimeoutIsSanitizedAndRetryKeepsTheSameIdempotencyKeyAndPayload(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new ConnectException('Private token or card code must not be exposed', new Request('POST', self::URL)),
            new Response(200, [], '{"success":true,"data":{"reservation":{"id":"abc"}}}'),
        ]));
        $stack->push(Middleware::history($history));
        $client = new GiftCardClient(new Client(['handler' => $stack]), self::URL, self::KEY);
        try {
            $client->reserve('GC-SECRET', 22, null, 2000, 'reserve:attempt:123', 7200);
            self::fail('Expected a timeout.');
        } catch (GiftCardApiException $error) {
            self::assertSame('temporarily_unavailable', $error->errorCode);
            self::assertStringNotContainsString('Private token', $error->getMessage());
        }

        $client->reserve('GC-SECRET', 22, null, 2000, 'reserve:attempt:123', 7200);
        self::assertCount(2, $history);
        self::assertSame($history[0]['request']->getHeaderLine('Idempotency-Key'), $history[1]['request']->getHeaderLine('Idempotency-Key'));
        self::assertSame((string) $history[0]['request']->getBody(), (string) $history[1]['request']->getBody());
    }
}
