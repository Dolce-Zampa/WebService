<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PurchaseProtection;

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;

/** Serialize cart writes and checkout creation while protection is enabled. */
final class CheckoutCartLock
{
    public static function enabled(): bool
    {
        return filter_var(env('PURCHASE_PROTECTION_CHECKOUT_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
    }

    /** @template T @param callable():T $callback @return T */
    public function run(int $cartId, callable $callback): mixed
    {
        if ($cartId < 1 || filter_var(env('APP_DISABLE_CACHE', false), FILTER_VALIDATE_BOOLEAN)) {
            throw new \RuntimeException('Purchase protection requires a shared cart lock.');
        }
        $cache = Cache::getFacadeRoot();
        $store = is_object($cache) && method_exists($cache, 'getStore') ? $cache->getStore() : null;
        if (!$store instanceof RedisStore) {
            throw new \RuntimeException('Purchase protection requires Redis.');
        }
        // The lock cannot expire while a remote request is still in flight.
        // A worker crash requires explicit cart reconciliation before release.
        $lock = $store->lock('purchase-protection-cart:' . $cartId, 0);
        if (!$lock->get()) {
            throw new \RuntimeException('The cart is already being updated.');
        }
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
