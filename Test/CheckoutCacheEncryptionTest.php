<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use PS\Webservice\Traits\UseCache;

final class CheckoutCacheHarness
{
    use UseCache;

    public function cacheCheckoutData(array $data): void
    {
        $this->tags(['order-session'])->setEncryptedToCache(42, $data, 60);
    }

    public function getCachedCheckoutData(): mixed
    {
        return $this->decryptCachedValue($this->tags(['order-session'])->getFromCache('42'));
    }
}

final class CheckoutCacheEncryptionTest extends TestCase
{
    public function test_checkout_cache_data_is_encrypted_and_round_trips(): void
    {
        $previousKey = getenv('APP_KEY');
        putenv('APP_KEY=checkout-cache-test-key');
        $_ENV['APP_KEY'] = 'checkout-cache-test-key';
        $stored = null;
        $taggedCache = $this->createMock(\Illuminate\Cache\TaggedCache::class);
        $taggedCache->method('put')->willReturnCallback(
            static function (string $key, mixed $value) use (&$stored): bool {
                $stored = $value;
                return true;
            }
        );
        $taggedCache->method('get')->willReturnCallback(
            static function (string $key) use (&$stored): mixed {
                return $stored;
            }
        );
        $cache = $this->createMock(\Illuminate\Cache\Repository::class);
        $cache->method('tags')->willReturn($taggedCache);
        Facade::setFacadeApplication(['cache' => $cache]);

        $harness = new CheckoutCacheHarness();
        $sensitiveData = ['customer' => ['email' => 'private@example.com', 'password' => 'checkout-cache-password-sentinel']];
        $harness->cacheCheckoutData($sensitiveData);

        self::assertIsString($stored);
        self::assertStringStartsWith('encrypted:v1:', $stored);
        self::assertStringNotContainsString('private@example.com', $stored);
        self::assertStringNotContainsString('checkout-cache-password-sentinel', $stored);
        self::assertSame($sensitiveData, $harness->getCachedCheckoutData());

        if ($previousKey === false) {
            putenv('APP_KEY');
            unset($_ENV['APP_KEY']);
        } else {
            putenv('APP_KEY=' . $previousKey);
            $_ENV['APP_KEY'] = $previousKey;
        }
    }
}
