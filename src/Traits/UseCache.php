<?php
declare(strict_types=1);

namespace PS\Webservice\Traits;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

trait UseCache
{

    private array $tags = [];

    protected function getFromCache(string $key): mixed
    {
        $key = sha1($key);
        if (Cache::tags($this->tag)->has($key)) {
            return Cache::tags($this->tag)->tags($this->tags)->get($key);
        }

        return null;
    }

    protected function setToCache(string $key, mixed $value, ?int $ttl = 1440): void
    {
        $key = sha1($key);
        if ($ttl === null) {
            Cache::tags($this->tag)->forever($key, $value);
        } else {
            $expiresAt = Carbon::now()->addMinutes($ttl);
            Cache::tags($this->tag)->put($key, $value, $expiresAt);
        }
    }

    protected function tags(array $tags): self
    {
        $this->tags = array_merge($this->tags, $tags);
        return $this;
    }

    protected function flush(): void
    {
        Cache::flush();
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
}