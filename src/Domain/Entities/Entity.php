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
    protected PrestashopServiceInterface $service;

    protected $tagsCache = 'entity:';

    private const KEY_CACHE = 'entity_cache';

    protected function __construct(array $data, PrestashopServiceInterface $service)
    {
        $this->service = $service;
        $this->data = $data;
        $tagsCache = [$this->tagsCache . empty($this->data['id']) ? Uuid::uuid4()->toString() : $this->data['id']];
        $tagsCache[] = 'entity:all';
        $tagsCache[] = $this->tagsCache . 'all';

        $this->tags($tagsCache);
        if($this->existsInCache(self::KEY_CACHE)) {
            $this->data = $this->getFromCache(self::KEY_CACHE);
        } else {
            $this->normalizeData();
            $this->setToCache(self::KEY_CACHE, $this->data);
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