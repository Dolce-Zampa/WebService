<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Entities;

use PS\Webservice\Domain\ObjectInterface;
use PS\Webservice\Service\PS\PrestashopServiceInterface;

class FilterEntity implements ObjectInterface
{
    /** @var array<string, mixed> */
    private array $data;
    private PrestashopServiceInterface $service;

    const MUST_HAVE_KEYS = ['features', 'attributes', 'price_range', 'manufacturers'];

    private function __construct(array $data, PrestashopServiceInterface $service)
    {
        $this->service = $service;
        $this->data = $data;
        $this->normalizeData();
    }

    public static function create(array $data, PrestashopServiceInterface $service): self
    {
        // if (!empty(array_diff(self::MUST_HAVE_KEYS, array_keys($data)))) {
        //     throw new \InvalidArgumentException('Missing required keys: ' . implode(', ', array_diff(self::MUST_HAVE_KEYS, array_keys($data))));
        // }

        return new self($data, $service);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    public function __get(string $name): mixed
    {
        if (!array_key_exists($name, $this->data)) {
            throw new \InvalidArgumentException('No argument found with ' . $name);
        }

        return $this->data[$name];
    }

    public function normalizeData(): void
    {
        $attributes = isset($this->data['attributes']) ? $this->data['attributes'] : [];
        $features = isset($this->data['features']) ? $this->data['features'] : [];

        $this->data = [
            'features' => [
                'materials' => $this->normalizeFeatureValues($features, 'Materiale'),
            ],
            'attributes' => [
                'colors' => $this->normalizeAttributeValues($attributes, 'Colore'),
                'sizes' => $this->normalizeAttributeValues($attributes, 'Taglia'),
            ],
            'price_range' => [
                'min' => (float) ($this->data['price_range']['min'] ?? 0),
                'max' => (float) ($this->data['price_range']['max'] ?? 0),
                'currency' => $this->data['price_range']['currency'] ?? '',
            ],
            'manufacturers' => [
                'values' => array_map(function ($value) {
                    return [
                        'id_manufacturer' => (int) ($value['id_manufacturer'] ?? 0),
                        'value' => $value['value'] ?? '',
                    ];
                }, $this->data['manufacturers']['values'] ?? []),
            ],
        ];
    }

    private function normalizeFeatureValues(array $feature, string $type): array
    {
        $materials = [];
        $object = [];

        if(empty($feature)) {
            return [];
        }

        foreach ($feature as $key => $data) {
            if ($data['name'] == $type) {
                $object = $feature[$key];
                foreach ($data['values'] as $value) {
                    $materials[] = [
                        "id_feature_value" => (int) $value['id_feature_value'],
                        "value" => $value['value'] ?? '',
                    ];
                }
            }
        }

        return [
            'id_feature' => (int) $object['id_feature'],
            'name' => (int) $object['name'],
            'type' => (int) $object['type'],
            'values' => $materials,
        ];
    }

    private function normalizeAttributeValues(array $attribute, string $type): array
    {
        if(empty($attribute)) {
            return [];
        }
        
        $typeAttribute = [];
        $object = [];
        foreach ($attribute as $key => $data) {
            if ($data['name'] == $type) {
                $object = $attribute[$key];
                foreach ($data['values'] as $value) {
                    $attr = [
                        "id_attribute" => (int) $value['id_attribute'],
                        "value" => $value['value'] ?? '',
                    ];

                    if($type === 'Colore') {
                        $attr['color'] = $value['color'] ?? '';
                    }

                    $typeAttribute[] = $attr;
                }
            }
        }

        if(empty($object)) {
            return [];
        }

        return [
            'id_attribute_group' => (int) $object['id_attribute_group'] ,
            'name' => (int) $object['name'],
            'type' => (int) $object['type'],
            'values' => $typeAttribute,
        ];
    }

	public function generatePayload(): \PS\Webservice\Domain\Object\PayloadServiceData
	{
		return new \PS\Webservice\Domain\Object\PayloadServiceData($this->toArray());
	}
}