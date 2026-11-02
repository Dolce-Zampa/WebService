<?php
declare(strict_types=1);

namespace PS\Webservice\Traits;

trait ProductManipulation {
    protected function calculateFullPrice(): void
    {
        // Both prices are calculated by PrestaShop with the applicable tax:
        // regular_price without reductions, original_price with reductions.
        // Never apply a fixed VAT rate in this service.
        $this->data['price'] = (float) ($this->data['regular_price'] ?? $this->data['price']);
    }

}