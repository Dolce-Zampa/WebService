<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Object;

use PS\Webservice\Traits\UuidGenerator;

final class Carrier
{
    use UuidGenerator;

    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
        $this->normalizeData();
    }

    public function normalizeData(array $toDecode = []): void
    {
        $carrier = [
            'id' => isset($this->data['id_relative']) ? $this->data['id_relative'] : $this->data['id'],
            'name' => $this->data['name'],
            'delay' => $this->data['delay'],
            'grade' => $this->data['grade'],
            'url' => $this->data['url'] ?? null,
            'active' => $this->data['active'],
            'deleted' => $this->data['deleted'],
            'is_free' => $this->data['is_free'],
            'shipping_handling' => $this->data['shipping_handling'],
            'shipping_external' => $this->data['shipping_external'],
            'range_behavior' => $this->data['range_behavior'],
            'shipping_method' => $this->data['shipping_method'],
            'max_width' => $this->data['max_width'],
            'max_height' => $this->data['max_height'],
            'max_depth' => $this->data['max_depth'],
            'max_weight' => $this->data['max_weight'],
            'price_with_tax' => $this->data['price_with_tax'] ?? null,
        ];

        $this->data = $carrier;
    }

    public function __get(string $name): mixed
    {
        return $this->data[$name];
    }

    public function toArray(): array
    {
        return $this->data;
    }

    
}