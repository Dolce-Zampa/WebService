<?php
declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Object\WebserviceConfig;
use PS\Webservice\Service\HttpService;
use PS\Webservice\Service\PS\Carrier;

final class CarrierRatesRoutingTest extends TestCase
{
    public function test_carrier_rates_and_policy_use_the_module_front_controller(): void
    {
        $previous = Facade::getFacadeApplication();
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"success":true,"data":{"carriers":[]}}'),
            new Response(200, [], '{"success":true,"data":{"free_shipping_threshold":99}}'),
        ]));
        $stack->push(Middleware::history($history));
        try {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(['log' => new class {
                public function debug(string $message, array $context): void {}
            }]);
            $http = new HttpService(new WebserviceConfig('https://shop.example/store/api', ['X-WS-Key' => 'test-key']), new Client(['handler' => $stack]));
            $carrier = new Carrier($http);
            self::assertCount(0, $carrier->carriersWithFixedRates());
            self::assertSame(['free_shipping_threshold' => 99.0], $carrier->shippingPolicy());
            self::assertSame('https://shop.example/store/index.php?fc=module&module=webserviceapi&controller=carrierrates', (string) $history[0]['request']->getUri());
            self::assertSame('https://shop.example/store/index.php?fc=module&module=webserviceapi&controller=shippingpolicy', (string) $history[1]['request']->getUri());
            self::assertSame('test-key', $history[0]['request']->getHeaderLine('X-WS-Key'));
            $http->setUrl('/carriers');
            self::assertSame('https://shop.example/store/api/carriers', $http->getUrl());
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previous);
        }
    }
}
