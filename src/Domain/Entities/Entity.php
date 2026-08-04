<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Entities;

use PS\Webservice\Service\PS\PrestashopServiceInterface;
use PS\Webservice\Traits\UseCache;

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
        $tagsCache = [$this->tagsCache . $this->data['id'] ?? ''];
        $tagsCache[] = 'entity:all';
        $tagsCache[] = $this->tagsCache . 'all';
        
        if($this->tags($tagsCache)->existsInCache(self::KEY_CACHE)) {
            $this->data = $this->tags($tagsCache)->getFromCache(self::KEY_CACHE);
        } else {
            $this->normalizeData();
            $this->tags($tagsCache)->setToCache(self::KEY_CACHE, $this->data);
        }
    }

    public function normalizeData(): void
    {
        
    }

}