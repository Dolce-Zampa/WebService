<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\HttpServiceInterface;
use PS\Webservice\Service\PS\Mailer;

final class RecoveryCartMailerTest extends TestCase
{
    public function test_promotion_is_inserted_in_the_existing_product_placeholder_and_escaped(): void
    {
        $http = $this->createMock(HttpServiceInterface::class);
        $payload = null;
        $http->expects(self::once())->method('invoke')->willReturnCallback(function ($method, $request) use (&$payload, $http) {
            $payload = $request->toArray();
            return $http;
        });
        (new Mailer($http))->sendRecoveryCartExpired('buyer@example.test', 'https://example.test', [], '10', 'Buyer', 'Usa <ORD3RFREE>');
        self::assertSame('Usa <ORD3RFREE>', $payload['template_vars']['message']);
        self::assertStringContainsString('Usa &lt;ORD3RFREE&gt;', $payload['template_vars']['products_list']);
    }
}
