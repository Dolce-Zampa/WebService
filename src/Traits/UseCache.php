<?php
declare(strict_types=1);

namespace PS\Webservice\Traits;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

trait UseCache
{

    protected function getFromCache(string $key): mixed
    {
        $key = sha1($key);
        if(Cache::has($key)) {
            return Cache::get($key);
        }

        return null;
    }

    protected function setToCache(string $key, mixed $value, int $ttl = 1440): void
    {
        $key = sha1($key);
        Cache::put($key, $value, Carbon::now()->addMinutes($ttl));
    }

    protected function removeFromCache(string $key): void
    {
        $key = sha1($key);
        Cache::forget($key);
    }

    protected function existsInCache(string $key): bool
    {
        $key = sha1($key);
        return Cache::has($key);
    }
}