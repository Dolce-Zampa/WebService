<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PS;

use PS\Webservice\Domain\Entities\CartEntity;
use PS\Webservice\Domain\Entities\CouponEntity;
use PS\Webservice\Service\HttpServiceInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use PS\Webservice\Traits\UuidGenerator;
use PS\Webservice\Service\PurchaseProtection\CheckoutCartLock;

class Cart extends Carrier implements PrestashopServiceInterface {

    use UuidGenerator;

    /** Use the native module route when protected checkout is enabled. */
    protected function setCheckoutModuleRoute(string $controller, string $legacyPath, array $query = []): void
    {
        if (CheckoutCartLock::enabled()) $this->httpService->setModuleController($controller, $query);
        else $this->httpService->setUrl($legacyPath);
    }

    public function getCartListFromUserId(?string $customerId = null, ?string $guestId = null): ?CartEntity
    {
        $queryString = http_build_query([
            !is_null($customerId) ? 'id_customer' : 'id_guest' => !is_null($customerId) ? $customerId : $guestId
        ]);

        $this->setCheckoutModuleRoute('cart', "/carts?{$queryString}", [
            !is_null($customerId) ? 'id_customer' : 'id_guest' => !is_null($customerId) ? $customerId : $guestId,
        ]);

        try {
            $response = $this->httpService->invoke('GET');
        } catch (\Exception $e) {
            Log::error("Exception occurred while retrieving cart for customer {$customerId}" . $e->getMessage());
            return null;
        }

        return CartEntity::create($response->toArray(), $this);
    }

    public function getCartFromId($cartId, $customerId = null, $guestId = null): ?CartEntity
    {
        $queryString = http_build_query([
            'id_cart' => $cartId,
            'id_customer' => $customerId,
            'id_guest' => $guestId,
        ]);
        $this->setCheckoutModuleRoute('cart', "/carts?{$queryString}", [
            'id_cart' => $cartId, 'id_customer' => $customerId, 'id_guest' => $guestId,
        ]);

        try {
            $response = $this->httpService->invoke('GET');
            if ($response->failed()) {
                if ($response->getHttpCode() === 404) return null;
                throw new PrestashopConnectorException($response);
            }
            $cartresult = $response->toArray();
            if(!isset($cartresult['data']['cart'])) {
                return null;
            }

        } catch (PrestashopConnectorException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error("Exception occurred while retrieving cart with id {$cartId}" . $e->getMessage());
            throw new PrestashopConnectorException($this->httpService, $e);
        }

        return CartEntity::create($cartresult['data']['cart'], $this);
    }

    public function newCart(array $cartOptions): HttpServiceInterface
    {
        $this->setCheckoutModuleRoute('cart', '/carts?ok=1');

        //create a payload
        $products = [
            'id_product' => (int) $cartOptions['productId'],
            'id_product_attribute' => (int) $cartOptions['productAttributeId'],
            'quantity' => (int) $cartOptions['qty'] ?? 1,
            'customizations' => $cartOptions['customizations'] ?? [],
            'notes' => $cartOptions['notes'] ?? null,
            'op' => 'up'
        ];
        $cartOptions['products'] = [$products];
        $payload = CartEntity::create($cartOptions, $this);

        try {
            $response = $this->httpService->invoke('POST', [
                'cart' => $payload->generatePayload()->toArray()
            ]);
        } catch (\Exception $e) {
            Log::error("Exception occurred while retrieving cart for customer {$payload->getId()}" . $e->getMessage());
            throw new PrestashopConnectorException($this->httpService, $e);
        }

        if($response->failed()) {
            Log::error('Failed to create cart', ['status' => $response->getHttpCode()]);
            throw new PrestashopConnectorException($response);
        }

        return $response;
    }

    /**
     * Retrieves the server-side price (with tax) for a product from the PrestaShop product catalog.
     * Never use prices provided by the frontend; always fetch from the server to prevent price manipulation.
     *
     * @param int $productId The product ID
     * @return float The effective product price including the applicable tax
     * @throws \RuntimeException When the product is not found or the API call fails
     */
    public function getProductPriceById(int $productId): float
    {
        $queryString = http_build_query([
            'display' => '[id,price]',
            'filter[id]' => $productId,
            'price' => ['original_price' => ['use_tax' => 1, 'use_reduction' => 1]],
        ]);
        $this->httpService->setUrl("/products?{$queryString}");

        try {
            $response = $this->httpService->invoke('GET');
            $products = $response->toArray()['products'] ?? [];
        } catch (\Exception $e) {
            Log::error("Failed to fetch server-side price for product #{$productId}: " . $e->getMessage());
            throw new \RuntimeException("Failed to fetch price for product #{$productId}");
        }

        if (empty($products)) {
            throw new \RuntimeException("Product #{$productId} not found in catalog. Ensure the product exists and is published.");
        }

        if (!isset($products[0]['original_price']) || !is_numeric($products[0]['original_price'])) {
            throw new \RuntimeException("Missing calculated price for product #{$productId}");
        }

        return round((float) $products[0]['original_price'], 2);
    }

    /**
     * Update cart
     * @param array $product
     * @param string $customerId
     * @return HttpServiceInterface
     */
    public function updateCart(array $product, $cartId, $customerId, bool $isGuest = false, ?string $op = 'up'): HttpServiceInterface
    {
        $type = $isGuest ? 'guest' : 'customer';
        $this->setCheckoutModuleRoute('cart', '/carts');

        //create a payload
        $products = [
            'id_product' => (int) $product['productId'],
            'id_product_attribute' => (int) $product['productAttributeId'],
            'quantity' => (int) $product['qty'] ?? 1,
            'id_customization' => (int) ($product['customizationId'] ?? 0),
            'notes' => $product['notes'] ?? null,
            'op' => $op
        ];

        if($isGuest) {
            $payload = CartEntity::create([
                'id' => $cartId,
                'products' => [$products],
                'id_guest' => $customerId,
                'customizations' => $product['customizations'] ?? [],
            ], $this);
        } else {
            $payload = CartEntity::create([
                'id' => $cartId,
                'products' => [$products],
                'id_customer' => $customerId,
                'customizations' => $product['customizations'] ?? [],
            ], $this);
        }

        try {
            $response = $this->httpService->invoke('POST', [
                'cart' => $payload->generatePayload()->toArray()
            ]);
        } catch (\Exception $e) {
            Log::error("Exception occurred while updating cart for customer {$payload->getId()}" . $e->getMessage());
            throw new PrestashopConnectorException($this->httpService, $e);
        }

        if($response->failed()) {
            if ($response->getHttpCode() === 404) {
                throw new CartUpdateNotFoundException($response);
            }
            Log::error('Failed to update cart', ['status' => $response->getHttpCode()]);
            throw new PrestashopConnectorException($response);
        }

        return $response;
    }

    public function getFeaturedCoupons(): Collection
    {
        $coupons = $this->getCartRules();
        if ($coupons->isEmpty()) {
            return new Collection();
        }

        $now = new \DateTimeImmutable();
        return $coupons->filter(static function (CouponEntity $coupon) use ($now): bool {
            $isActive = (bool) $coupon->active === true;
            $hasQuantity = (int) $coupon->quantity > 0;
            $validFromRaw = trim((string) $coupon->valid_from);
            $validToRaw = trim((string) $coupon->valid_to);

            if ($validFromRaw === '') {
                $isStartDateValid = true;
            } else {
                try {
                    $dateFrom = new \DateTimeImmutable($validFromRaw);
                    $isStartDateValid = $dateFrom <= $now;
                } catch (\Exception) {
                    Log::warning("Invalid cart rule valid_from value encountered: {$validFromRaw}");
                    $isStartDateValid = false;
                }
            }

            if ($validToRaw === '') {
                $isEndDateValid = true;
            } else {
                try {
                    $dateTo = new \DateTimeImmutable($validToRaw);
                    $isEndDateValid = $dateTo >= $now;
                } catch (\Exception) {
                    Log::warning("Invalid cart rule valid_to value encountered: {$validToRaw}");
                    $isEndDateValid = false;
                }
            }

            return $isActive && $hasQuantity && $isStartDateValid && $isEndDateValid;
        })->values();
    }

    public function getCouponDetail(string $code): ?CouponEntity
    {
        $coupons = $this->getCartRules([
            'code' => $code,
        ]);

        if ($coupons->isEmpty()) {
            return null;
        }

        foreach ($coupons as $coupon) {
            if (strcasecmp((string) $coupon->code, $code) === 0) {
                return $coupon;
            }
        }

        return null;
    }

    public function prepareCouponCart($cartId, $customerId, $guestId, int $carrierId, array $codes, array $checkout = []): CartEntity
    {
        $codes = array_values(array_unique($codes));
        if ($codes === []) {
            $codes = [''];
        }
        foreach ($codes as $code) {
            $this->setCheckoutModuleRoute('coupon', '/cart_rules');
            $response = $this->httpService->invoke('POST', [
                'id_cart' => $cartId, 'id_customer' => $customerId, 'id_guest' => $guestId,
                'id_carrier' => $carrierId, 'checkout' => true, 'code' => $code,
                'delivery_address' => $checkout['delivery_address'] ?? null,
                'invoice_address' => $checkout['invoice_address'] ?? null,
                'email' => $checkout['customer']['email'] ?? null,
                'firstname' => $checkout['customer']['firstname'] ?? null,
                'lastname' => $checkout['customer']['lastname'] ?? null,
            ]);
            $data = $response->toArray();
            if ($response->failed() || empty($data['data']['cart'])) {
                throw new \InvalidArgumentException('Unable to validate coupon cart.');
            }
        }
        return CartEntity::create($data['data']['cart'], $this);
    }

    /** Check that PrestaShop can accept the configured service before offering it. */
    public function purchaseProtectionAvailable(int $productId): bool
    {
        if ($productId < 1) return false;
        try {
            $this->httpService->setModuleController('purchaseprotection', ['id_product' => $productId]);
            $response = $this->httpService->invoke('GET');
            $data = $response->toArray();
            if ($response->failed()) {
                Log::warning('Purchase protection readiness endpoint returned an error.', [
                    'product_id' => $productId,
                    'http_status' => $response->getHttpCode(),
                ]);
                return false;
            }
            $available = ($data['success'] ?? false) === true
                && ($data['data']['available'] ?? false) === true
                && (int) ($data['data']['product_id'] ?? 0) === $productId;
            if (!$available) {
                Log::warning('Purchase protection readiness endpoint returned an invalid availability response.', [
                    'product_id' => $productId,
                    'success' => ($data['success'] ?? false) === true,
                    'available' => ($data['data']['available'] ?? false) === true,
                    'returned_product_matches' => (int) ($data['data']['product_id'] ?? 0) === $productId,
                ]);
            }
            return $available;
        } catch (\Throwable $error) {
            Log::warning('Purchase protection module is unavailable.', ['exception_type' => get_class($error)]);
            return false;
        }
    }

    /** Update only the configured protection service through the authenticated module. */
    public function setPurchaseProtection(int $cartId, mixed $customerId, mixed $guestId, int $productId, ?int $priceCents): CartEntity
    {
        if ($cartId < 1 || $productId < 1 || ($priceCents !== null && $priceCents < 1)) {
            throw new \InvalidArgumentException('Invalid purchase protection request.');
        }
        // /api/* may be intercepted by PrestaShop's native webservice.
        $this->httpService->setModuleController('purchaseprotection');
        $response = $this->httpService->invoke('POST', [
            'id_cart' => $cartId, 'id_customer' => $customerId, 'id_guest' => $guestId,
            'id_product' => $productId, 'price_cents' => $priceCents,
        ]);
        $data = $response->toArray();
        if ($response->failed() || empty($data['data']['cart'])) {
            throw new \RuntimeException('Unable to update purchase protection.');
        }
        return CartEntity::create($data['data']['cart'], $this);
    }

    public function validateCoupon(string $code, string $cartId, ?string $customerId = null, ?string $guestId = null): array|false
    {
        $query = [
            'code' => $code,
            'id_cart' => $cartId,
        ];

        if ($customerId !== null) {
            $query['id_customer'] = $customerId;
        }

        if ($guestId !== null) {
            $query['id_guest'] = $guestId;
        }

        $data = $this->invokeCartRules($query);
        if ($data === null) {
            return false;
        }

        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        if (!is_array($data)) {
            return false;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return Collection<int, CouponEntity>
     */
    private function getCartRules(array $queryParams = []): Collection
    {
        $data = $this->invokeCartRules($queryParams);
        if ($data === null) {
            return new Collection();
        }

        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        $rows = [];
        if (isset($data['cart_rules']) && is_array($data['cart_rules'])) {
            $rows = array_values(array_filter($data['cart_rules'], 'is_array'));
        } elseif (array_is_list($data)) {
            $rows = array_values(array_filter($data, 'is_array'));
        }

        $collection = new Collection();
        foreach ($rows as $row) {
            $collection->push(CouponEntity::create($row, $this));
        }

        return $collection;
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>|null
     */
    private function invokeCartRules(array $queryParams): ?array
    {
        $queryString = http_build_query($queryParams);
        $this->setCheckoutModuleRoute('coupon', "/cart_rules?{$queryString}", $queryParams);

        try {
            $response = $this->httpService->invoke('GET');
        } catch (\Exception $e) {
            Log::error("Exception occurred while retrieving cart rules: " . $e->getMessage());
            return null;
        }

        if ($response->failed()) {
            Log::error('Failed to retrieve cart rules', ['status' => $response->getHttpCode()]);
            return null;
        }

        $data = $response->toArray();
        return is_array($data) ? $data : null;
    }

    public function deleteCart($cartId, $customerId = null, $guestId = null): HttpServiceInterface
    {
        $queryParams = [
            'id_cart' => $cartId,
        ];

        $payload = [
            'id_cart' => $cartId,
        ];

        if ($customerId !== null) {
            $payload['id_customer'] = $customerId;
        }

        if ($guestId !== null) {
            $payload['id_guest'] = $guestId;
        }

        $queryString = http_build_query($queryParams);
        $this->setCheckoutModuleRoute('cart', "/carts?{$queryString}", $queryParams);

        try {
            return $this->httpService->invoke('DELETE', $payload);
        } catch (\Exception $e) {
            Log::error("Exception occurred while deleting cart with id {$cartId}: " . $e->getMessage());
            throw new PrestashopConnectorException($this->httpService, $e);
        }
    }
}
