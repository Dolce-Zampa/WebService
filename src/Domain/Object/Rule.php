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
        $rule = [
            "id" => $this->encodeId((int) $this->data['id'], 'cart-rule'),
            "rule" => $this->data['rule'],
            "conditions" => $this->data['conditions'] ?? [],
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