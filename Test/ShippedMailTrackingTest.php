<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;

final class ShippedMailTrackingTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_tracking_number_is_distinct_from_order_reference_in_both_formats(): void
    {
        require __DIR__ . '/stubs/mail-template-hook-runtime.php';
        $module = new MailTemplateHookTestModule();
        $extra = [];
        $module->hookActionGetExtraMailTemplateVars([
            'template' => 'shipped',
            'template_vars' => ['{id_order}' => 42, '{order_name}' => 'ORDER42'],
            'extra_template_vars' => &$extra,
        ]);
        foreach (['html', 'txt'] as $format) {
            $template = file_get_contents(__DIR__ . '/../resources/mails/it/shipped.' . $format);
            self::assertNotFalse($template);
            $rendered = strtr($template, ['{order_name}' => 'ORDER42'] + $extra);
            self::assertMatchesRegularExpression('/Numero di tracciamento[^\n]*BRT123456/', $rendered);
            self::assertDoesNotMatchRegularExpression('/Numero di tracciamento[^\n]*ORDER42/', $rendered);
        }
    }
}
