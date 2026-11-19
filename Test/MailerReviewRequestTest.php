<?php

declare(strict_types=1);

use PS\Webservice\Service\HttpServiceInterface;
use PS\Webservice\Service\PS\Mailer;
use PHPUnit\Framework\TestCase;

final class MailerReviewRequestTest extends TestCase
{
    private ?string $previousReviewUrl;

    protected function setUp(): void
    {
        $this->previousReviewUrl = $_ENV['TRUSTPILOT_REVIEW_URL'] ?? null;
        $_ENV['TRUSTPILOT_REVIEW_URL'] = 'https://it.trustpilot.com/review/www.dolcezampa.com';
    }

    protected function tearDown(): void
    {
        if ($this->previousReviewUrl === null) {
            unset($_ENV['TRUSTPILOT_REVIEW_URL']);
            return;
        }

        $_ENV['TRUSTPILOT_REVIEW_URL'] = $this->previousReviewUrl;
    }

    public function test_sends_the_customer_to_the_configured_trustpilot_page(): void
    {
        $http = $this->createMock(HttpServiceInterface::class);
        $payload = null;
        $http->expects(self::once())->method('setUrl')->with('/mailer?debug=true');
        $http->expects(self::once())->method('invoke')->with(
            'POST',
            self::callback(function ($request) use (&$payload): bool {
                $payload = $request->toArray();
                return true;
            }),
        );

        (new Mailer($http))->sendReviewRequestMail('cliente@example.test', 'Giulia', 42);

        self::assertSame('Racconta la tua esperienza con Dolce & Zampa', $payload['subject']);
        self::assertSame('https://it.trustpilot.com/review/www.dolcezampa.com', $payload['template_vars']['review_url']);
        self::assertArrayNotHasKey('products', $payload['template_vars']);
    }

    public function test_refuses_to_send_to_a_non_trustpilot_url(): void
    {
        $_ENV['TRUSTPILOT_REVIEW_URL'] = 'https://nottrustpilot.com/reviews';
        $http = $this->createMock(HttpServiceInterface::class);
        $http->expects(self::never())->method('invoke');

        $this->expectException(LogicException::class);
        (new Mailer($http))->sendReviewRequestMail('cliente@example.test', 'Giulia', 42);
    }
}
