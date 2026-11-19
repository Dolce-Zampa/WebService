<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class MailTemplateHookTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_shop_invoice_is_removed_from_order_emails(): void
    {
        require __DIR__ . '/stubs/mail-template-hook-runtime.php';
        $module = new MailTemplateHookTestModule();
        $invoice = ['content' => 'invoice', 'name' => 'invoice.pdf', 'mime' => 'application/pdf'];
        $delivery = ['content' => 'delivery', 'name' => 'delivery.pdf', 'mime' => 'application/pdf'];
        $attachment = $invoice;
        $params = ['template' => 'order_conf', 'fileAttachment' => &$attachment];
        $module->hookActionEmailSendBefore($params);
        self::assertNull($attachment);

        $attachment = ['invoice' => $invoice, 'delivery' => $delivery];
        $params = ['template' => 'payment', 'templateVars' => ['{id_order}' => 42], 'fileAttachment' => &$attachment];
        $module->hookActionEmailSendBefore($params);
        self::assertSame(['delivery' => $delivery], $attachment);

        $attachment = ['invoice' => $invoice];
        $module->hookActionEmailSendBefore($params);
        self::assertNull($attachment);

        $attachment = ['invoice' => $invoice];
        $params['templateVars'] = [];
        $module->hookActionEmailSendBefore($params);
        self::assertSame(['invoice' => $invoice], $attachment);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_shipping_keeps_native_tracking_and_confirmation_keeps_production_times(): void
    {
        require __DIR__ . '/stubs/mail-template-hook-runtime.php';
        $module = new MailTemplateHookTestModule();
        $extra = ['{other}' => 'preserved'];
        $params = [
            'template' => 'shipped',
            'template_vars' => ['{id_order}' => 42, '{followup}' => 'https://carrier.example/track/123'],
            'extra_template_vars' => &$extra,
        ];
        $module->hookActionGetExtraMailTemplateVars($params);
        self::assertSame(['{other}' => 'preserved'], $extra);
        self::assertSame('https://carrier.example/track/123', $params['template_vars']['{followup}']);

        $params['template'] = 'order_conf';
        $params['template_vars']['{products}'] = 'HTML products';
        $params['template_vars']['{products_txt}'] = 'Text products';
        $module->hookActionGetExtraMailTemplateVars($params);
        self::assertSame('preserved', $extra['{other}']);
        self::assertStringContainsString('HTML products', $extra['{products}']);
        self::assertStringContainsString('Collare: 3 giorni', $extra['{products}']);
        self::assertStringContainsString('Text products', $extra['{products_txt}']);
        self::assertStringContainsString('Collare: 3 giorni', $extra['{products_txt}']);
    }
}
