<?php
declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Object\WebserviceConfig;
use PS\Webservice\Service\HttpService;

final class HttpServiceLoggingTest extends TestCase
{
    public function test_http_error_logs_origin_without_query_or_stack_arguments(): void
    {
        $this->checkFailure(new Response(404, [], 'Not found'), 404);
    }

    public function test_connection_error_is_logged_without_a_response(): void
    {
        $this->checkFailure(new ConnectException('secret exception text', new Request('GET', 'http://prestashop')), null);
    }

    private function checkFailure(Response|ConnectException $result, ?int $status): void
    {
        $previous = Facade::getFacadeApplication();
        $previousServer = $_SERVER;
        $logger = new class {
            public array $context = [];
            public function debug(string $message, array $context): void {}
            public function critical(string $message, array $context): void { $this->context = $context; }
        };
        try {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(['log' => $logger]);
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/api/products?token=incoming-secret';
            $client = new Client(['handler' => HandlerStack::create(new MockHandler([$result]))]);
            $service = new HttpService(new WebserviceConfig('http://prestashop/api'), $client);
            $service->setUrl('/products?ws_key=outgoing-secret');
            $service->invoke('GET');

            self::assertSame($status ?? 500, $service->getHttpCode());
            self::assertSame($status, $logger->context['status']);
            self::assertSame('http://prestashop/api/products', $logger->context['url']);
            self::assertSame('/api/products', $logger->context['incoming_path']);
            self::assertSame('GET', $logger->context['incoming_method']);
            self::assertStringContainsString('HttpServiceLoggingTest->checkFailure', json_encode($logger->context['stack']));
            self::assertStringNotContainsString('secret', json_encode($logger->context));
        } finally {
            $_SERVER = $previousServer;
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previous);
        }
    }
}
