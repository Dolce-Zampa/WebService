<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Object;

use PS\Webservice\Domain\Entities\CarrierEntity;
use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Domain\Entities\ProductEntity;
use PS\Webservice\Domain\Object\Discount;
use PS\Webservice\Domain\ObjectInterface;
use PS\Webservice\Service\PS\Order;
use PS\Webservice\Service\PS\PrestashopServiceInterface;
use PS\Webservice\Traits\UuidGenerator;

class OrderSession implements ObjectInterface
{
    use UuidGenerator;
    protected array $data;
    private ?float $payableTotal = null;
    private Order $service;

    private CustomerEntity $customer;
    private function __construct(array $data, Order $service)
    {
        $this->data = $data;
        $this->service = $service;
        $this->normalizeData();
    }

    public static function create(array $data, PrestashopServiceInterface $service): self
    {
        /** @var Order $service */
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

        $carrierId = $data['id_carrier'] ?? null;
        if (is_null($carrierId)) {
            throw new \InvalidArgumentException('Carrier ID is required for payment session');
        }

        $this->customer = $customer;
        $customerDetails = $customer->toArray();
        $metadata = [
            'cart_id' => (string) $cartId,
            'id_customer' => (string) ($data['id_customer'] ?? ''),
            'id_guest' => (string) ($data['id_guest'] ?? ''),
            'id_carrier' => (string) $data['id_carrier'],
            'coupon_code' => (string) ($data['discounts'][0]['coupon'] ?? ''),
            'recovery_attempt' => $this->metadataBoolean($data['recovery_attempt'] ?? false, 'recovery_attempt'),
            'create_account' => $this->metadataBoolean($data['create_account'] ?? false, 'create_account'),
            'newsletter' => $this->metadataBoolean($customerDetails['newsletter'] ?? false, 'newsletter'),
        ];
        $paymentModule = $data['payment_module'] ?? env('PAYMENT_MODULE');
        if ($paymentModule !== null && $paymentModule !== '') {
            if (!is_string($paymentModule) || !preg_match('/^[a-z][a-z0-9_-]*$/i', $paymentModule)) {
                throw new \InvalidArgumentException('Invalid payment module.');
            }
            $metadata['payment_module'] = $paymentModule;
        }

        $this->data = [
            'mode' => 'payment',
            // 'permissions' => [ 
            //     'update_discounts' => 'server_only',
            // ],
            'success_url' => $_ENV['STRIPE_SUCCESS_URL'] ?? '',
            'cancel_url' => $_ENV['STRIPE_CANCEL_URL'] ?? '',
            'expires_at' => $data['expires_at'] ?? time() + 3600, // Scade tra 1 ora (3600 secondi)
            'line_items' => $data['line_items'] ?? [],
            // Only include IDs with positive integer values; null, empty strings, '0',
            // and negative values are excluded as all PrestaShop entity IDs must be > 0.
            'metadata' => $metadata,
        ];

    }

    private function metadataBoolean(mixed $value, string $name): string
    {
        if (!is_bool($value) && !in_array($value, [0, 1, '0', '1'], true)) {
            throw new \InvalidArgumentException($name . ' must be a boolean value.');
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
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

    /** Creates a payment line from an ownership-checked, server-fetched PrestaShop cart. */
    public function addCartLineItem(array $product): void
    {
        if (!isset($product['price_wt']) || !is_numeric($product['price_wt']) ||
            !is_finite((float) $product['price_wt']) || (float) $product['price_wt'] < 0 ||
            (int) ($product['quantity'] ?? 0) <= 0) {
            throw new \InvalidArgumentException('Invalid server cart product price or quantity');
        }

        $name = (string) $product['name'];
        $attributes = trim((string) ($product['attributes'] ?? ''));
        if ($attributes !== '' && !str_contains($name, $attributes)) {
            $name .= ' - ' . $attributes;
        }
        $productData = [
            'name' => $name,
            'metadata' => [
                'id_product' => (string) $product['id_product'],
                'id_product_attribute' => (string) ($product['id_product_attribute'] ?? 0),
            ],
        ];
        if (!empty($product['reference'])) {
            $productData['description'] = (string) $product['reference'];
        }
        // Cart image IDs may be returned as "productId-imageId" by PrestaShop.
        $imageParts = explode('-', (string) ($product['id_image'] ?? ''));
        $imageId = (int) end($imageParts);
        if ($imageId > 0) {
            $productData['images'] = [build_product_image_url($imageId, $name, 'small_default')];
        }

        $this->data['line_items'][] = [
            'price_data' => [
                'currency' => 'eur',
                'product_data' => $productData,
                'unit_amount' => (int) round((float) $product['price_wt'] * 100),
            ],
            'quantity' => (int) $product['quantity'],
        ];
    }

    public function addLineItem(ProductEntity $product, int $quantity, float $price, string $type = 'product'): void
    {
        $this->data['line_items'][] = [
            'price_data' => [
                'currency' => 'eur',
                'product_data' => [
                    'name' => $product->name,
                    'images' => [build_product_image_url($product->getImages()[0]['id'], $product->name, 'small_default')],
                ],
                'unit_amount' => (int) round($price * 100),
            ],
            'quantity' => $quantity
        ];
    }

    public function addCarrierLineItem(string $name, int $quantity, float $price, string $type = 'product'): void
    {
        $this->data['line_items'][] = [
            'price_data' => [
                'currency' => 'eur',
                'product_data' => [
                    'name' => $name,
                ],
                'unit_amount' => (int) round($price * 100),
            ],
            'quantity' => $quantity
        ];
    }

    public function applyCouponCart(array $cart): void
    {
        if (empty($cart['currency_iso'])) {
            throw new \InvalidArgumentException('Cart currency is required.');
        }
        $currency = strtolower($cart['currency_iso']);
        if ($currency !== 'eur') {
            throw new \InvalidArgumentException('Unsupported cart currency.');
        }
        $shipping = $cart['totals']['shipping_tax_incl'] ?? null;
        $grandTotal = $cart['totals']['grand_total_tax_incl'] ?? null;
        if (!is_numeric($shipping) || !is_numeric($grandTotal) || !is_finite((float) $shipping) || !is_finite((float) $grandTotal) || $shipping < 0 || $grandTotal < 0) {
            throw new \InvalidArgumentException('Invalid discounted cart totals.');
        }
        if ($shipping > 0) {
            $this->addCarrierLineItem('Shipping', 1, (float) $shipping);
        }
        $discount = round($this->total() - (float) $grandTotal, 2);
        if ($discount < -0.01 || $discount > $this->total()) {
            throw new \InvalidArgumentException('Discounted cart total does not match payment lines.');
        }
        $code = (string) ($cart['cart_rules'][0]['code'] ?? '');
        $this->data['metadata']['coupon_code'] = $code;
        if ($discount > 0) {
            $this->addDiscount(new Discount('Cart ' . $cart['id'] . ' discount', $discount, $code, type: 'amount'));
        }
        $this->payableTotal = (float) $grandTotal;
    }

    public function payableTotal(): float
    {
        return $this->payableTotal ?? $this->total();
    }

    public function addDiscount(Discount $discount): void
    {
        $existingCoupon = $discount->type === 'amount' ? null : $this->service->findExistingStripeCoupon($discount->code);

        if ($existingCoupon) {
            $stripeCouponId = $existingCoupon->id;
        } else {
            $stripeCouponId = $this->service->createCouponCode($discount);
        }

        $this->data['metadata']['coupon_code'] = $discount->code;
        // Stripe Checkout supports one aggregate discount.
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

    public function service(): Order
    {
        return $this->service;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function getLineItems(): array
    {
        return $this->data['line_items'] ?? [];
    }

    public function getCustomer(): CustomerEntity
    {
        return $this->customer;
    }

    public function toCacheData(): array
    {
        return [
            'orderSession' => [
                'metadata' => $this->data['metadata'],
                'customer' => $this->customer->toArray(),
            ],
        ];
    }

    public function hash(): string
    {
        return md5(json_encode($this->data));
    }
}