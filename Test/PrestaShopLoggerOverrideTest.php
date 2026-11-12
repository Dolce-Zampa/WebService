<?php

use PHPUnit\Framework\TestCase;

final class PrestaShopLoggerOverrideTest extends TestCase
{
    public function testOverrideSmokeInIsolatedProcess(): void
    {
        $this->runFixture('prestashop-logger-override-smoke.php', 'ALL TESTS PASSED');
    }

    public function testRealLogtailHandlerCanBeConstructed(): void
    {
        $this->runFixture('prestashop-logger-real-handler.php', 'Real Logtail handler constructed');
    }

    private function runFixture(string $fixture, string $expected): void
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/' . $fixture) . ' 2>&1';
        exec($command, $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertStringContainsString($expected, implode("\n", $output));
    }
}
