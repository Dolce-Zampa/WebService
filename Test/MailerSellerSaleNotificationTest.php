<?php

declare(strict_types=1);

use PS\Webservice\Domain\Object\WebserviceConfig;
use PS\Webservice\Service\HttpServiceInterface;
use PS\Webservice\Service\PS\Mailer;
use PS\Webservice\Service\PS\PrestashopConnectorException;
use PHPUnit\Framework\TestCase;

final class MailerSellerSaleNotificationTest extends TestCase
{
    public function test_confirms_successful_seller_sale_notification(): void
    {
        $http = $this->createMock(HttpServiceInterface::class);
        $http->expects(self::once())->method('setUrl')->with('/mailer?debug=true');
        $http->expects(self::once())->method('invoke')->with('POST')->willReturnSelf();
        $http->expects(self::once())->method('failed')->willReturn(false);
        $http->expects(self::once())->method('toArray')->willReturn(['success' => true]);

        $this->mailer($http)->sendSellerSaleNotification(
            'seller@example.test',
            'Seller',
            'ORDER-83',
            '2026-10-22 10:32:51',
            [['name' => 'Product', 'quantity' => 1, 'amount' => '10.00']],
            '10.00',
            'payment_accepted',
        );
    }

    public function test_rejects_failed_http_response_for_seller_sale_notification(): void
    {
        $http = $this->createMock(HttpServiceInterface::class);
        $http->method('invoke')->willReturnSelf();
        $http->method('failed')->willReturn(true);
        $http->method('getHttpCode')->willReturn(500);
        $http->method('getConfig')->willReturn(new WebserviceConfig('https://prestashop.example.test'));
        $http->method('getUrl')->willReturn('https://prestashop.example.test/api/mailer');

        $this->expectException(PrestashopConnectorException::class);
        $this->mailer($http)->sendSellerSaleNotification(
            'seller@example.test',
            'Seller',
            'ORDER-83',
            '2026-10-22 10:32:51',
            [],
            '10.00',
            'payment_accepted',
        );
    }

    private function mailer(HttpServiceInterface $http): Mailer
    {
        return new Mailer($http);
    }
}
