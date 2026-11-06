<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class CategoryCacheWebhookTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_form_events_preserve_previous_categories(): void
    {
        require __DIR__ . '/stubs/category-cache-webhook-runtime.php';
        $module = new CategoryCacheWebhookTestModule();
        Product::$categories[7] = [13, 17];
        Product::$defaults[7] = 17;
        $module->hookActionBeforeUpdateProductFormHandler(['id' => 7]);
        Product::$categories[7] = [32];
        Product::$defaults[7] = 32;
        $module->hookActionAfterUpdateProductFormHandler(['id' => 7, 'form_data' => []]);
        self::assertSame([[7, [13, 17], [32]]], $module->events);
        $module->hookActionAfterCreateProductFormHandler(['id' => 8, 'form_data' => []]);
        self::assertSame([8, [], []], $module->events[1]);
        Product::$categories[9] = [12, 31];
        Product::$defaults[9] = 12;
        $product = new Product(9);
        $module->hookActionObjectProductDeleteBefore(['object' => $product]);
        Product::$categories[9] = [];
        $module->hookActionObjectProductDeleteAfter(['object' => $product]);
        self::assertSame([9, [12, 31], []], $module->events[2]);
    }
}
