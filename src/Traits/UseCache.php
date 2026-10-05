<?php
declare(strict_types=1);

namespace PS\Webservice\Traits;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

trait UseCache
{

    protected array $tags = [];

    protected function getFromCache(string $key): mixed
    {
        if (env('APP_DISABLE_CACHE', false)) {
            return null;
        }
        return Cache::tags($this->tags)->get(sha1($key));
    }
    // "c275d4f4-2011-7091-eb17-87e208e05738" "57f6dd7f7506f497933045377e718d257a0b9006"
    /**
     * Summary of setToCache
     * @param mixed $key
     * @param mixed $value
     * @param mixed $ttl in minutes
     * @return void
     */
    protected function setToCache(mixed $key, mixed $value, ?int $ttl = null): void
    {
        $key = sha1((string) $key);
        if(env("APP_DISABLE_CACHE", false)) {
            return;
        }

        if (empty($ttl)) {
            Cache::tags($this->tags)->forever($key, $value);
        } else {
            $expiresAt = Carbon::now()->addMinutes($ttl);
            Cache::tags($this->tags)->put($key, $value, $expiresAt);
        }
    }

    protected function setEncryptedToCache(mixed $key, array $value, ?int $ttl = null): void
    {
        $plainText = json_encode($value, JSON_THROW_ON_ERROR);
        $iv = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt(
            $plainText,
            'aes-256-gcm',
            $this->cacheEncryptionKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($cipherText === false) {
            throw new \RuntimeException('Unable to encrypt cached checkout data.');
        }

        $this->setToCache($key, $this->encryptedCachePrefix() . base64_encode($iv . $tag . $cipherText), $ttl);
    }

    protected function decryptCachedValue(mixed $value): mixed
    {
        $prefix = $this->encryptedCachePrefix();
        if (!is_string($value) || !str_starts_with($value, $prefix)) {
            return $value;
        }

        $encoded = substr($value, strlen($prefix));
        $payload = base64_decode($encoded, true);
        if ($payload === false || strlen($payload) < 28) {
            throw new \RuntimeException('Invalid encrypted checkout cache data.');
        }

        $plainText = openssl_decrypt(
            substr($payload, 28),
            'aes-256-gcm',
            $this->cacheEncryptionKey(),
            OPENSSL_RAW_DATA,
            substr($payload, 0, 12),
            substr($payload, 12, 16)
        );
        if ($plainText === false) {
            throw new \RuntimeException('Unable to decrypt cached checkout data.');
        }

        return json_decode($plainText, true, 512, JSON_THROW_ON_ERROR);
    }

    private function cacheEncryptionKey(): string
    {
        $applicationKey = (string) env('APP_KEY', '');
        if ($applicationKey === '') {
            throw new \RuntimeException('APP_KEY is required to encrypt checkout cache data.');
        }

        return hash('sha256', $applicationKey, true);
    }

    private function encryptedCachePrefix(): string
    {
        return 'encrypted:v1:';
    }

    protected function tags(array $tags): self
    {
        $this->tags = $tags;
        return $this;
    }

    protected function flush(): void
    {
        Cache::flush();
    }

    protected function flushTag(): void
    {
        Cache::tags($this->tags)->flush();
    }

    protected function removeFromCache(string $key): void
    {
        $key = sha1($key);
        Cache::tags($this->tags)->forget($key);
    }

    protected function existsInCache(string $key): bool
    {
        $key = sha1($key);

        return Cache::tags($this->tags)->has($key);
    }

    protected function getOrSet(callable $function, array $data): void
    {
        $cacheKey = json_encode($data);
        $cachedData = $this->getFromCache($cacheKey);
        if ($cachedData !== null) {
            // return $cachedData;
        }

        $data = $function();
        $this->setToCache($cacheKey, $data);

    }
}