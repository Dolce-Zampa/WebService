<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Entities;

use PS\Webservice\Service\PS\PrestashopServiceInterface;
use PS\Webservice\Traits\UseCache;
use Ramsey\Uuid\Uuid;

class Entity
{
    use UseCache;

    protected array $data;
    protected ?PrestashopServiceInterface $service;

    protected $tagsCache = 'entity:';
    protected $cacheTTL = 5; // 5 minutes

    protected function __construct(array $data, ?PrestashopServiceInterface $service)
    {
        $this->service = $service;
        $this->data = $data;
        
        // 1. Definiamo una chiave univoca basata sull'ID (o un UUID se non esiste)
        $entityId = $this->data['id'] ?? Uuid::uuid4()->toString();
        $cacheKey = $this->tagsCache . $entityId;

        // 2. Prepariamo i tag per la pulizia in blocco
        $tagsCache = [
            'entity',
            $this->tagsCache . $entityId
        ];

        // 3. Usiamo i tag corretti concatenati alla chiave univoca
        $this->tags($tagsCache);
        
        if($this->existsInCache($cacheKey)) {
            $this->data = $this->getFromCache($cacheKey);
        } else {
            $this->normalizeData();
            $this->setToCache($cacheKey, $this->data, $this->cacheTTL);
        }
    }

    public function normalizeData(): void
    {
        
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

}