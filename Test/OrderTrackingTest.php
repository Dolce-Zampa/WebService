<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class OrderTrackingTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_carrier_shipment_data_and_safe_fallbacks(): void
    {
        require __DIR__ . '/stubs/order-tracking-runtime.php';
        $order = new FakeTrackingOrder();
        $details = new FakeShipmentDetails();
        \Logeecom\Infrastructure\ServiceRegister::$details = $details;
        Carrier::$template = 'https://fallback.example/track/@';

        self::assertSame(['number' => 'PACK123', 'url' => $details->url], MlabOrderTracking::forOrder($order));

        $details->numbers = ['BRT<&'];
        $extra = [];
        (new TrackingMailModule())->hookActionGetExtraMailTemplateVars([
            'template' => 'shipped', 'template_vars' => ['{id_order}' => 42], 'extra_template_vars' => &$extra,
        ]);
        self::assertStringContainsString('BRT&lt;&amp;', $extra['{tracking_block_html}']);
        self::assertStringContainsString('https://carrier.example/track/PACK123?lang=it&amp;src=store', $extra['{tracking_block_html}']);
        self::assertStringContainsString($details->url, $extra['{tracking_block_text}']);

        $details->url = 'javascript:alert(1)';
        $details->numbers = ['BRT 123'];
        self::assertSame(['number' => 'BRT 123', 'url' => 'https://fallback.example/track/BRT%20123'], MlabOrderTracking::forOrder($order));
        Module::$enabled = false;
        $order->number = '';
        self::assertSame(['number' => null, 'url' => null], MlabOrderTracking::forOrder($order));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_internal_batch_returns_tracking_and_rejects_invalid_ids(): void
    {
        require __DIR__ . '/stubs/order-tracking-runtime.php';
        \Logeecom\Infrastructure\ServiceRegister::$details = new FakeShipmentDetails();
        $controller = new TrackingOrderController();
        $result = $controller->tracking('42,43');
        self::assertSame('PACK123', $result['tracking_by_order'][42]['number']);
        self::assertSame('https://carrier.example/track/PACK123?lang=it&src=store', $result['tracking_by_order'][43]['url']);
        $this->expectException(MlabFactoryApiException::class);
        $controller->tracking('42,not-a-number');
    }
}
