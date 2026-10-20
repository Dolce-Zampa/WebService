<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PS\Webservice\Domain\Enums\TemplateMail;

final class MailTemplateAvailabilityTest extends TestCase
{
    public function test_each_application_mail_template_is_available_in_italian(): void
    {
        $mailDirectory = dirname(__DIR__) . '/resources/mails/it';

        foreach (TemplateMail::cases() as $template) {
            $htmlTemplate = $mailDirectory . '/' . $template->value . '.html';
            $textTemplate = $mailDirectory . '/' . $template->value . '.txt';

            self::assertTrue(
                is_file($htmlTemplate) || is_file($textTemplate),
                sprintf('Missing Italian mail template for "%s".', $template->value),
            );
        }
    }
}
