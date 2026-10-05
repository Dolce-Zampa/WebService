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
        // Configuration files wrap each rule in a "rule" object, while callers
        // may also provide an already-normalized rule.
        $data = is_array($this->data['rule'] ?? null) ? $this->data['rule'] : $this->data;
        $rule = [
            "id" => (int) ($data['id'] ?? 0),
            "rule" => $data['rule'] ?? null,
            "conditions" => $data['conditions'] ?? [],
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
