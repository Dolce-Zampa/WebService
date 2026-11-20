<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\HttpServiceInterface;
use PS\Webservice\Service\MailjetService;

final class MailjetNewsletterSubscriptionTest extends TestCase
{
    public function test_does_not_call_mailjet_when_newsletter_consent_is_not_given(): void
    {
        $http = $this->createMock(HttpServiceInterface::class);
        $http->expects(self::never())->method('setUrl');

        (new MailjetService($http))->subscribeToCustomerNewsletter(
            'customer@example.com',
            'Ada',
            'Rossi',
            false,
            10663907,
        );
    }

    public function test_subscribes_to_both_customer_newsletter_lists_after_explicit_consent(): void
    {
        $http = $this->createMock(HttpServiceInterface::class);
        $urls = [];
        $requests = [];

        $http->method('setUrl')->willReturnCallback(static function (string $url) use (&$urls): void {
            $urls[] = $url;
        });
        $http->method('invoke')->willReturnCallback(function (string $method, array $payload = []) use (&$requests, $http): HttpServiceInterface {
            $requests[] = [$method, $payload];
            return $http;
        });
        $http->method('failed')->willReturn(false);
        $http->method('getBody')->willReturn(json_encode(['Data' => [['ID' => 123]]], JSON_THROW_ON_ERROR));

        (new MailjetService($http))->subscribeToCustomerNewsletter(
            'customer@example.com',
            'Ada',
            'Rossi',
            true,
            10663907,
        );

        self::assertSame([
            '/v3/REST/contact',
            '/v3/REST/listrecipient',
            '/v3/REST/listrecipient',
        ], $urls);
        self::assertSame([
            ['POST', [
                'Email' => 'customer@example.com',
                'Name' => 'Ada Rossi',
                'IsExcludedFromCampaigns' => false,
            ]],
            ['POST', ['ContactID' => 123, 'ListID' => 10663907]],
            ['POST', ['ContactID' => 123, 'ListID' => 10517938]],
        ], $requests);
    }
}
