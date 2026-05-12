<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Object;

use PS\Webservice\Traits\UuidGenerator;

final class Rule
{
    use UuidGenerator;

    const TYPE_DISCOUNT = [
        'amount' => 'amount',
        'percentage' => 'percentage',
        'free_shipping' => 'free-shipping',
    ];

    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
        $this->normalizeData();
    }

    public function normalizeData(array $toDecode = []): void
    {
        if(!in_array($this->data['rule'] ?? '', self::TYPE_DISCOUNT)) {
            throw new \InvalidArgumentException('Invalid discount type: ' . ($this->data['rule'] ?? 'null'));
        }

        $rule = [
            "id" => $this->encodeId((int) $this->data['id'], 'cart-rule'),
            "stripe_id" => "PS_" . $this->data['id'],
            "rule" => $this->data['rule'],
            "conditions" => [
                "valid-from" => $this->data['conditions']['valid_from'] ?? "2000-01-01",
                "valid-to" => $this->data['conditions']['valid_to'] ?? "2100-01-01",
                "minimum-spend" => $this->data['conditions']['minimum_spend'] ?? 1.00,
                "applicable" => $this->data['conditions']['applicable'] ?? [],
                "excluded" => $this->data['conditions']['excluded'] ?? [],
                "discount" => [
                    "type" => $this->data['conditions']['discount']['type'] ?? throw new \InvalidArgumentException('Discount type is required for cart rule'), // Default to 'amount' if not specified
                    "value" => $this->data['conditions']['discount']['value'] ?? throw new \InvalidArgumentException('Discount value is required for cart rule'),
                ]
            ]
        ];

        $this->data = $rule;
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    
}