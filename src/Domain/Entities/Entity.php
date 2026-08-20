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

        // Senza un identificatore stabile non possiamo cachare in modo
        // sensato (ogni istanza avrebbe una chiave diversa, causando solo
        // scritture inutili e cache-miss garantiti). In questo caso ci
        // limitiamo a normalizzare i dati e basta.
        if ($entityId === null) {
            $this->normalizeData();

            return;
        }

        // static::class distingue le sottoclassi: Product e Category
        // con lo stesso id numerico non si sovrascrivono più a vicenda.
        $cacheKey = static::class . ':' . $entityId;

        $tags = [
            $this->cacheTag,
            static::class,
            $cacheKey,
        ];

        $this->tags($tags);

        $cached = $this->getFromCache($cacheKey);

        if ($cached !== null) {
            $this->data = $cached;

            return;
        }

        $this->normalizeData();
        $this->setToCache($cacheKey, $this->data, $this->cacheTTL);
    }

    protected function normalizeData(): void
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}