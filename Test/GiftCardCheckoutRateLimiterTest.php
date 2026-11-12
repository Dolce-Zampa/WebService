<?php
declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Service\GiftCard\GiftCardCheckoutRateLimiter;

final class GiftCardCheckoutRateLimiterTest extends TestCase
{
    public function test_limits_guesses_per_cart_and_per_address(): void
    {
        $previousFacade = Facade::getFacadeApplication();
        $previousEnv = $_ENV['APP_ENV'] ?? null;
        $_ENV['APP_ENV'] = 'testing';
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(['cache' => new Repository(new ArrayStore())]);
        try {
            $limiter = new GiftCardCheckoutRateLimiter();
            for ($i = 0; $i < 5; ++$i) self::assertTrue($limiter->allow('192.0.2.1', 42));
            self::assertFalse($limiter->allow('192.0.2.1', 42));
            self::assertTrue($limiter->allow('192.0.2.1', 43));
            self::assertTrue($limiter->allow('192.0.2.1', 44));
            self::assertFalse($limiter->allow('192.0.2.1', 45));
            self::assertTrue($limiter->allow('192.0.2.2', 46));
        } finally {
            if ($previousEnv === null) unset($_ENV['APP_ENV']); else $_ENV['APP_ENV'] = $previousEnv;
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previousFacade);
        }
    }
}
