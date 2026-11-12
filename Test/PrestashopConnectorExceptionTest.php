<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\HttpServiceInterface;
use PS\Webservice\Service\HttpService;
use PS\Webservice\Domain\Object\WebserviceConfig;
use PS\Webservice\Service\PS\PrestashopConnectorException;

final class PrestashopConnectorExceptionTest extends TestCase
{
    public function testConnectorErrorsNeverExposeUpstreamDetailsOrPreviousException(): void
    {
        $service = $this->createMock(HttpServiceInterface::class);
        $service->expects(self::never())->method('getConfig');
        $service->expects(self::never())->method('getBody');
        $service->expects(self::never())->method('getUrl');

        $exception = new PrestashopConnectorException($service, new RuntimeException('sensitive upstream detail'));

        self::assertSame(424, $exception->getCode());
        self::assertSame('Unable to communicate with PrestaShop.', $exception->getMessage());
        self::assertNull($exception->getPrevious());
        self::assertStringNotContainsString('sensitive upstream detail', (string) $exception);
    }

    public function testRedirectIsTreatedAsFailure(): void
    {
        $service = new HttpService(new WebserviceConfig('https://example.invalid'));
        $status = new ReflectionProperty(HttpService::class, 'httpCode');
        $status->setValue($service, 302);

        self::assertTrue($service->failed());
    }
}
