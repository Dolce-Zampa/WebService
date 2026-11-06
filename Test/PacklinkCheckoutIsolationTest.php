<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PacklinkCheckoutIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__ . '/fixtures/packlink_checkout_hook.php';
    }

    public function testProviderFailureDoesNotEscapeOrderValidationHook(): void
    {
        $module = (new ReflectionClass(Packlink::class))->newInstanceWithoutConstructor();
        $module->hookActionValidateOrder(['order' => new Order()]);
        self::assertCount(1, PrestaShopLogger::$messages);
        self::assertStringContainsString('123', PrestaShopLogger::$messages[0]);
    }

    public function testProviderFailureDoesNotEscapeOrderStatusHook(): void
    {
        $module = (new ReflectionClass(Packlink::class))->newInstanceWithoutConstructor();
        $module->hookActionOrderStatusUpdate(['id_order' => 123]);
        self::assertCount(1, PrestaShopLogger::$messages);
    }

    public function testLegacyDraftCreationIsSkippedAfterCutover(): void
    {
        $module = (new ReflectionClass(Packlink::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Packlink::class, 'createOrderDraft');
        self::assertNull($method->invoke($module, new Order(), new OrderState()));
    }
}
