<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\HttpServiceInterface;
use PS\Webservice\Service\MailjetService;
use PS\Webservice\Service\NewsletterTrackingConsentService;

final class NewsletterTrackingConsentServiceTest extends TestCase
{
    private DB $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->capsule = new DB();
        $this->capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $schema = $this->capsule->schema();
        $schema->create('newsletter_tracking_consents', function ($table): void {
            $table->increments('id');
            $table->unsignedBigInteger('customer_id')->unique();
            $table->boolean('tracking_allowed');
            $table->dateTime('selected_at');
            $table->string('policy_version', 64);
            $table->string('source', 64);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
        $schema->create('newsletter_tracking_consent_events', function ($table): void {
            $table->increments('id');
            $table->unsignedBigInteger('customer_id');
            $table->boolean('tracking_allowed');
            $table->string('policy_version', 64);
            $table->string('source', 64);
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');
        });
    }

    public function test_stores_the_current_choice_and_an_audit_event_after_mailjet_accepts_it(): void
    {
        $mailjet = $this->createMock(MailjetService::class);
        $mailjetCalls = [];
        $mailjet->expects(self::exactly(2))
            ->method('setOpenTrackingConsent')
            ->willReturnCallback(static function (string $email, bool $allowed) use (&$mailjetCalls): void {
                $mailjetCalls[] = [$email, $allowed];
            });

        $service = new NewsletterTrackingConsentService(
            $this->capsule->getConnection(),
            $mailjet,
            'newsletter_tracking_consents',
            'newsletter_tracking_consent_events',
        );

        self::assertNull($service->preferenceForCustomer(41));

        $service->recordChoice(41, 'customer@example.com', true);
        $createdAt = DB::table('newsletter_tracking_consents')->where('customer_id', 41)->value('created_at');
        $service->recordChoice(41, 'customer@example.com', false);

        self::assertFalse($service->preferenceForCustomer(41));
        self::assertSame($createdAt, DB::table('newsletter_tracking_consents')->where('customer_id', 41)->value('created_at'));
        self::assertSame(2, DB::table('newsletter_tracking_consent_events')->where('customer_id', 41)->count());
        self::assertSame([1, 0], DB::table('newsletter_tracking_consent_events')
            ->where('customer_id', 41)
            ->orderBy('id')
            ->pluck('tracking_allowed')
            ->map(static fn ($value): int => (int) $value)
            ->all());
        self::assertSame([
            ['customer@example.com', true],
            ['customer@example.com', false],
        ], $mailjetCalls);
    }

    public function test_updates_mailjet_open_tracking_consent_using_the_system_property(): void
    {
        $http = $this->createMock(HttpServiceInterface::class);
        $http->expects(self::once())
            ->method('setUrl')
            ->with('/v3/REST/contactdata/customer%2Bprivacy%40example.com');
        $http->expects(self::once())
            ->method('invoke')
            ->with('PUT', [
                'Data' => [[
                    'Name' => 'mjoptinopen',
                    'Value' => '0',
                ]],
            ])
            ->willReturnSelf();
        $http->expects(self::once())->method('failed')->willReturn(false);

        (new MailjetService($http))->setOpenTrackingConsent('customer+privacy@example.com', false);
    }
}
