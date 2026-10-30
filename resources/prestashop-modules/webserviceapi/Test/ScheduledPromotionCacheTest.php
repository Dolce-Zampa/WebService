<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/ScheduledPromotionCache.php';

final class ScheduledPromotionCacheTest extends TestCase
{
    public function test_scan_notifies_products_before_saving_checkpoint(): void
    {
        $events = [];
        $count = ScheduledPromotionCache::run('2026-10-29 12:01:00',
            fn () => '2026-10-29 12:00:00',
            function ($from, $to) use (&$events) { $events[] = [$from, $to]; return [12, 15]; },
            function ($id) use (&$events) { $events[] = $id; return true; },
            function ($time) use (&$events) { $events[] = $time; return true; }
        );
        self::assertSame(2, $count);
        self::assertSame([['2026-10-29 12:00:00', '2026-10-29 12:01:00'], 12, 15, '2026-10-29 12:01:00'], $events);
    }

    public function test_failed_webhook_does_not_advance_checkpoint(): void
    {
        $saved = false;
        try {
            ScheduledPromotionCache::run('2026-10-29 12:01:00', fn () => false,
                fn ($from, $to) => [12], fn ($id) => false,
                function ($time) use (&$saved) { $saved = true; return true; });
            self::fail('Expected a failure.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('#12', $e->getMessage());
            self::assertFalse($saved);
        }
    }

    public function test_database_failure_does_not_advance_checkpoint(): void
    {
        $saved = false;
        try {
            ScheduledPromotionCache::run('2026-10-29 12:01:00', fn () => false,
                fn ($from, $to) => false, fn ($id) => true,
                function ($time) use (&$saved) { $saved = true; return true; });
            self::fail('Expected a failure.');
        } catch (RuntimeException $e) {
            self::assertFalse($saved);
        }
    }
}
