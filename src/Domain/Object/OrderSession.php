<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Object;

use PS\Webservice\Domain\Entities\CarrierEntity;
use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Domain\Object\Discount;
use PS\Webservice\Domain\ObjectInterface;
use PS\Webservice\Service\PS\PrestashopServiceInterface;
use PS\Webservice\Traits\UuidGenerator;

class OrderSession implements ObjectInterface
{
    use UuidGenerator;
    protected array $data;
    private function __construct(array $data, private PrestashopServiceInterface $service)
    {
        $this->data = $data;
        $this->normalizeData();
    }

    public static function create(array $data, PrestashopServiceInterface $service): self
    {
        return new self($data, $service);
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
        $cartId = $data['cart_id'] ?? throw new \InvalidArgumentException('cart_id is required to create an order session');
        /**
         * @var  CustomerEntity $customer
         */
        $customer = $data['customer'];
        if (!$customer instanceof CustomerEntity) {
            throw new \InvalidArgumentException('customer must be an instance of CustomerEntity to create an order session');
        }

        $customerDetails = $customer->toArray();
        $this->data = [
            'mode' => 'payment',
            // 'permissions' => [ 
            //     'update_discounts' => 'server_only',
            // ],
            'success_url' => $this->appendQueryParam($data['success_url'] ?? '', 'cart_id', $cartId),
            'cancel_url' => $this->appendQueryParam($data['cancel_url'] ?? '', 'cart_id', $cartId),
            'line_items' => $data['line_items'] ?? [],
            // Only include IDs with positive integer values; null, empty strings, '0',
            // and negative values are excluded as all PrestaShop entity IDs must be > 0.
            'metadata' => [
                'cart_id' => $this->decodeId($cartId, 'cart'),
                'id_customer' => $this->decodeId($data['id_customer'], 'customer'),
                'id_guest' => $this->decodeId($data['id_guest'], 'guest'),
                'id_carrier' => $data['id_carrier'],
                'customer' => json_encode($customerDetails),
            ],
        ];

    }

    /**
     * Appends a query parameter to a URL, correctly handling existing query strings.
     */
    private function appendQueryParam(string $url, string $param, mixed $value): string
    {
        if ($value === null || $value === '') {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . urlencode($param) . '=' . urlencode((string) $value);
    }

    public function addLineItem(string $name, int $quantity, float $price, string $type = 'product'): void
    {
        $this->data['line_items'][] = [
            'price_data' => [
                'currency' => 'eur',
                'product_data' => [
                    'name' => $name
                ],
                'unit_amount' => (int) ($price * 100),
            ],
            'quantity' => $quantity
        ];
    }

    public function addDiscount(Discount $discount): void
    {
        $existingCoupon = $this->service->findExistingStripeCoupon($discount->code);

        if ($existingCoupon) {
            $stripeCouponId = $existingCoupon->id;
        } else {
            $stripeCouponId = $this->service->createStripeCoupon($discount);
        }
        
        // 3. Struttura corretta per Stripe Checkout
        $this->data['discounts'] = [
            [
                'coupon' => $stripeCouponId
            ]
        ];

    }

    public function addCarrier(CarrierEntity $carrier): void
    {
        $this->data['shipping_options']['shipping_rate'] = 'shr_1TVqLaK37RWIfqdNW4HF98Df'; //FIXME: we need to create a shipping rate in Stripe for this carrier and use its ID here 
    }

    public function generatePayload(): PayloadServiceData
    {
        return new PayloadServiceData($this->data, ['id_cart' => 'cart', 'id_customer' => 'customer', 'id_guest' => 'guest']);
    }

    public function total(): float
    {
        $calculatedTotal = 0;
        foreach ($this->data['line_items'] as $item) {
            $calculatedTotal += $item['price_data']['unit_amount'] * $item['quantity'];
        }

        return $calculatedTotal / 100; // Convert back to euros

    }
}