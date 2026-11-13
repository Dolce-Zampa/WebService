<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ShippedMailTrackingTest extends TestCase
{
    public function test_tracking_number_is_distinct_from_order_reference_in_both_formats(): void
    {
        foreach (['html', 'txt'] as $format) {
            $template = file_get_contents(__DIR__ . '/../resources/mails/it/shipped.' . $format);
            self::assertNotFalse($template);
            $rendered = strtr($template, ['{order_name}' => 'ORDER42', '{shipping_number}' => 'BRT123456']);
            self::assertMatchesRegularExpression('/Numero di tracciamento[^\n]*BRT123456/', $rendered);
            self::assertDoesNotMatchRegularExpression('/Numero di tracciamento[^\n]*ORDER42/', $rendered);
            $withoutTracking = strtr($template, ['{order_name}' => 'ORDER42', '{shipping_number}' => '']);
            self::assertDoesNotMatchRegularExpression('/Numero di tracciamento[^\n]*ORDER42/', $withoutTracking);
        }
    }
}
