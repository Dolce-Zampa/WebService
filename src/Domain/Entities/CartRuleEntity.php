<?php

declare(strict_types=1);

namespace PS\Webservice\Domain\Entities;

use Carbon\Carbon;
use PS\Webservice\Domain\Object\Rule;
use PS\Webservice\Domain\ObjectInterface;
use PS\Webservice\Service\PS\PrestashopServiceInterface;
use PS\Webservice\Traits\UuidGenerator;

class CartRuleEntity implements ObjectInterface
{
    use UuidGenerator;

    private array $data;
    private PrestashopServiceInterface $service;

    private function __construct(array $data, PrestashopServiceInterface $service)
    {
        $this->service = $service;
        $this->data = $data;
        $this->normalizeData();
    }

    public static function create(array $data, PrestashopServiceInterface $service): self
    {
        return new self($data, $service);
    }

    public function __get(string $name): mixed
    {
        if (!array_key_exists($name, $this->data)) {
            throw new \InvalidArgumentException('Property not found: ' . $name);
        }

        return $this->data[$name];
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function toJson($options = 0)
    {
        return json_encode($this->toArray(), $options);
    }

    public function normalizeData(): void
    {
        foreach ($this->data as $key => $value) {
                $this->data[$key] = (new Rule($value))->toArray();
        }
    }

    public function generatePayload(): \PS\Webservice\Domain\Object\PayloadServiceData
    {
        return new \PS\Webservice\Domain\Object\PayloadServiceData(
            data: $this->toArray()
        );
    }

}