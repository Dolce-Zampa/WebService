<?php
declare(strict_types=1);

namespace DolzeZampa\WS\Domain\Object;

use DolzeZampa\WS\Domain\ObjectInterface;
use DolzeZampa\WS\Service\PS\PrestashopServiceInterface;

class OrderSession implements ObjectInterface
{

    protected array $data;

    private function __construct(array $data) {
        $this->data = $data;
        $this->normalizeData();
    }

    public static function create(array $data, PrestashopServiceInterface $service): self
    {
        return new self($data);
    }

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    public function normalizeData(): void
    {
        $data = $this->data;
        $cartId = $data['cart_id'] ?? null;

        $this->data = [
            'mode' => 'payment',
            'success_url' => ($data['success_url'] ?? '') . ($cartId !== null ? '?cart_id=' . $cartId : ''),
            'cancel_url' => ($data['cancel_url'] ?? '') . ($cartId !== null ? '?cart_id=' . $cartId : ''),
            'line_items' => $data['line_items'] ?? [],
            'metadata' => array_filter([
                'cart_id' => isset($data['cart_id']) ? (string) $data['cart_id'] : null,
                'id_customer' => isset($data['id_customer']) ? (string) $data['id_customer'] : null,
                'id_guest' => isset($data['id_guest']) ? (string) $data['id_guest'] : null,
                'id_carrier' => isset($data['id_carrier']) ? (string) $data['id_carrier'] : null,
            ], fn($v) => $v !== null && $v !== ''),
        ];
    }

    public function addLineItem(string $name, int $quantity, float $price): void
    {
        $this->data['line_items'][] = [
            'price_data' => [
                'currency' => 'eur',
                'product_data' => [
                    'name' => $name
                ],
                'unit_amount' => (int)($price * 100),
            ],
            'quantity' => $quantity
        ];
    }

}