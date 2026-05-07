<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Object;

use PS\Webservice\Traits\UuidGenerator;

final class PayloadServiceData
{
    use UuidGenerator;

    private array $data;

    public function __construct(array $data, array $toDecode = [])
    {
        $this->data = $data;
        $this->normalizeData($toDecode);
    }

    public function normalizeData(array $toDecode = []): void
    {
        foreach ($toDecode as $key => $entity) {
            if (isset($this->data[$key])) {
                $this->data[$key] = $this->decodeId($this->data[$key], $entity);
            }
        }
    }

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function toArray(): array
    {
        return $this->data;
    }

    
}