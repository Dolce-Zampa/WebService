<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Object;

final class Discount
{
    public function __construct(
        public string $name,
        public float $amount_off,
        public string $code,
        public string $duration = 'once',
    ) {}

    public function toArray(): array
    {
        return [
            'coupon_data' => [
                'name' => $this->name,
                'amount_off' => $this->amount_off * 100, // Stripe expects amount_off in cents
                'currency' => 'eur'
            ]
        ];
    }
}