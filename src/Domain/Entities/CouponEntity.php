<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Entities;

use PS\Webservice\Domain\ObjectInterface;
use PS\Webservice\Service\PS\PrestashopServiceInterface;
use PS\Webservice\Traits\UuidGenerator;

class CouponEntity implements ObjectInterface
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
        $name = $this->data['name'] ?? '';
        if (is_array($name)) {
            $name = $this->extractLanguageValue($name);
        }

        // Canonical internal fields are valid_from/valid_to; date_from/date_to are input aliases.
        // Both naming conventions are kept in output for backward compatibility with existing API clients.
        $validFrom = (string) ($this->data['valid_from'] ?? $this->data['date_from'] ?? '');
        $validTo = (string) ($this->data['valid_to'] ?? $this->data['date_to'] ?? '');

        $this->data = [
            'id' => isset($this->data['id']) ?  $this->encodeId($this->data['id'], 'coupon') : null,
            'code' => isset($this->data['code']) ? (string) $this->data['code'] : '',
            'name' => (string) $name,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            // Kept for backward compatibility with existing API consumers.
            'date_from' => $validFrom,
            'date_to' => $validTo,
            'quantity' => isset($this->data['quantity']) ? (int) $this->data['quantity'] : 0,
            'active' => isset($this->data['active']) ? (bool) $this->data['active'] : false,
            'reduction_percent' => isset($this->data['reduction_percent']) ? (float) $this->data['reduction_percent'] : 0.0,
            'reduction_amount' => isset($this->data['reduction_amount']) ? (float) $this->data['reduction_amount'] : 0.0,
        ];
    }

    private function extractLanguageValue(array $languageArray): string
    {
        if (empty($languageArray)) {
            return '';
        }

        if (isset($languageArray['language'])) {
            if (is_array($languageArray['language'])) {
                $firstLang = reset($languageArray['language']);
                return is_array($firstLang) && isset($firstLang['value'])
                    ? (string) $firstLang['value']
                    : (string) $firstLang;
            }

            return (string) $languageArray['language'];
        }

        return (string) reset($languageArray);
    }

    public function generatePayload(): \PS\Webservice\Domain\Object\PayloadServiceData
    {
        return new \PS\Webservice\Domain\Object\PayloadServiceData($this->toArray(), ['id', 'coupon']);
    }
}
