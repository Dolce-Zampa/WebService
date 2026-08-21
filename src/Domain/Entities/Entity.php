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
        $this->normalizeData();
        
    }

    public function normalizeData(): void
    {
        
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

}