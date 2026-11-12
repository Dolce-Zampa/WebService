<?php
declare(strict_types=1);

namespace PS\Webservice\Service\GiftCard;

use Illuminate\Support\Facades\Cache;

/** Shared fixed-window limits protect short, bearer-style card codes from guessing. */
final class GiftCardCheckoutRateLimiter
{
    public function allow(string $remoteAddress, int $cartId): bool
    {
        if ($cartId < 1) {
            return false;
        }
        $cache = Cache::getFacadeRoot();
        $store = is_object($cache) && method_exists($cache, 'getStore') ? $cache->getStore() : null;
        $isDevelopment = in_array(strtolower((string) env('APP_ENV', 'production')), ['dev', 'development', 'local', 'test', 'testing'], true);
        if (!is_object($cache) || !method_exists($cache, 'add') || !method_exists($cache, 'increment')
            || (!$isDevelopment && !$store instanceof \Illuminate\Cache\RedisStore)) {
            throw new \RuntimeException('Gift card checkout rate limiter is unavailable.');
        }
        $window = intdiv(time(), 60);
        $keys = [
            ['global', 120],
            ['ip:' . hash('sha256', $remoteAddress), 8],
            ['cart:' . $cartId, 5],
        ];
        foreach ($keys as [$scope, $limit]) {
            $key = 'gift-card-guess:' . $window . ':' . $scope;
            $cache->add($key, 0, 120);
            $count = $cache->increment($key);
            if (!is_int($count) || $count < 1) {
                throw new \RuntimeException('Gift card checkout rate limiter failed.');
            }
            if ($count > $limit) {
                return false;
            }
        }
        return true;
    }
}
