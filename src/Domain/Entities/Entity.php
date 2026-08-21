<?php

declare(strict_types=1);

namespace PS\Webservice\Domain\Entities;

use PS\Webservice\Service\PS\PrestashopServiceInterface;
use PS\Webservice\Traits\UseCache;

class Entity
{
    use UseCache;

    protected array $data;
    protected ?PrestashopServiceInterface $service;

    /**
     * Prefisso base dei tag di cache. Viene sempre concatenato con
     * static::class in modo che le sottoclassi non collidano mai
     * tra loro (es. Product:5 !== Category:5).
     */
    protected string $cacheTag = 'entity';

    protected int $cacheTTL = 5; // minuti

    protected function __construct(array $data, ?PrestashopServiceInterface $service)
    {
        $this->service = $service;
        $this->data = $data;

        $entityId = $this->data['id'] ?? null;

        if ($entityId === null) {
            $this->normalizeData();
            return;
        }

        $cacheKey = static::class . ':' . $entityId;

        $tags = [
            "entity",
            $this->cacheTag,
            $this->cacheTag . ':' . $entityId,
        ];

        $this->tags($tags);

        $cached = $this->getFromCache($cacheKey);

        if ($cached !== null) {
            $this->data = $cached;
            //check if the hash of the cached data is the same as the current data
            if (isset($this->data['hash']) && $this->data['hash'] === $this->hash()) {
                return;
            }
        }

        $this->normalizeData();

        // save the hash of the data to the cache to detect changes in the future
        $this->data['hash'] = $this->hash();
        $this->setToCache($cacheKey, $this->data, $this->cacheTTL);
    }

    protected function normalizeData(): void
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function hash(): string
    {
        return md5(json_encode($this->data));
    }
}