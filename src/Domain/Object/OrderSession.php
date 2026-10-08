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
        if (!is_numeric($carrierId) || (int) $carrierId <= 0) {
            throw new \InvalidArgumentException('A valid carrier ID is required for payment session');
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
        if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
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

    public function addCartShipping(array $cart): void
    {
        $shipping = $cart['totals']['shipping_tax_incl'] ?? $cart['total_shipping_tax_incl'] ?? null;
        if (!is_numeric($shipping) || !is_finite((float) $shipping) || (float) $shipping < 0) {
            throw new \InvalidArgumentException('Cart shipping total is required and must be valid.');
        }

        if ((float) $shipping > 0) {
            $this->addCarrierLineItem('Shipping', 1, (float) $shipping);
        }
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
        $carrierId = $carrier->get('id');
        if (!is_numeric($carrierId) || (int) $carrierId <= 0 ||
            (int) ($this->data['metadata']['id_carrier'] ?? 0) !== (int) $carrierId) {
            throw new \InvalidArgumentException('The Stripe shipping carrier must match the selected carrier.');
        }

        $configuredRates = $_ENV['STRIPE_SHIPPING_RATE_IDS'] ?? getenv('STRIPE_SHIPPING_RATE_IDS') ?: '';
        $rates = json_decode((string) $configuredRates, true);
        if (!is_array($rates)) {
            throw new \InvalidArgumentException('STRIPE_SHIPPING_RATE_IDS must be a JSON object mapping carrier IDs to Stripe shipping rate IDs.');
        }

        $rateId = $rates[(string) (int) $carrierId] ?? null;
        if (!is_string($rateId) || !preg_match('/^shr_[A-Za-z0-9]+$/D', $rateId)) {
            throw new \InvalidArgumentException('No valid Stripe shipping rate is configured for carrier ' . (int) $carrierId . '.');
        }

        $this->data['shipping_options'] = [['shipping_rate' => $rateId]];
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