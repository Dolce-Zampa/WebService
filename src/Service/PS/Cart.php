<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PS;

use PS\Webservice\Domain\Entities\CartEntity;
use PS\Webservice\Service\HttpServiceInterface;
use Illuminate\Support\Facades\Log;
use PS\Webservice\Traits\UuidGenerator;

class Cart extends Carrier implements PrestashopServiceInterface {

    use UuidGenerator;

    public function getCartListFromUserId(?string $customerId = null, ?string $guestId = null): ?CartEntity
    {
        $customerId = $this->decodeId($customerId, 'customer');
        $guestId = $this->decodeId($guestId, 'guest');

        $queryString = http_build_query([
            !is_null($customerId) ? 'id_customer' : 'id_guest' => !is_null($customerId) ? $customerId : $guestId
        ]);

        $this->httpService->setUrl("/carts?{$queryString}");

        try {
            $response = $this->httpService->invoke('GET');
        } catch (\Exception $e) {
            Log::error("Exception occurred while retrieving cart for customer {$customerId}" . $e->getMessage());
            return null;
        }

        return CartEntity::create($response->toArray(), $this);
    }

    public function getCartFromId(string $cartId, ?string $customerId = null, ?string $guestId = null): ?CartEntity
    {
        $queryString = http_build_query([
            'id_cart' => $this->decodeId($cartId, 'cart'),
            'id_customer' => $this->decodeId($customerId, 'customer'),
            'id_guest' => $this->decodeId($guestId, 'guest'),
            'ws_key' => $this->httpService->getConfig()->apikey
        ]);
        $this->httpService->setUrl("/carts?{$queryString}");

        try {
            $response = $this->httpService->invoke('GET');
            $cartresult = $response->toArray();
            if(!isset($cartresult['data']['cart'])) {
                return null;
            }

        } catch (\Exception $e) {
            Log::error("Exception occurred while retrieving cart with id {$cartId}" . $e->getMessage());
            return null;
        }

        return CartEntity::create($cartresult['data']['cart'], $this);
    }

    public function newCart(array $cartOptions): HttpServiceInterface
    {
        $this->httpService->setUrl("/carts?ws_key={$this->httpService->getConfig()->apikey}");

        //create a payload
        $products = [
            'id_product' => (int) $cartOptions['productId'],
            'id_product_attribute' => (int) $cartOptions['productAttributeId'],
            'quantity' => (int) $cartOptions['qty'] ?? 1
        ];
        $cartOptions['products'] = [$products];
        $payload = CartEntity::create($cartOptions, $this);

        try {
            $response = $this->httpService->invoke('POST', [
                'cart' => $payload->generatePayload()->toArray()
            ]);
        } catch (\Exception $e) {
            Log::error("Exception occurred while retrieving cart for customer {$payload->getId()}" . $e->getMessage());
        }

        if($response->failed()) {
            Log::error("Failed to create cart for customer {$payload->getId()}, code:" . $response->getHttpCode() . ", body: " . $response->getBody());
            return $response;
        }

        return $response;
    }

    /**
     * Retrieves the server-side price (with tax) for a product from the PrestaShop product catalog.
     * Never use prices provided by the frontend; always fetch from the server to prevent price manipulation.
     *
     * @param int $productId The product ID
     * @return float The product price including 22% VAT
     * @throws \RuntimeException When the product is not found or the API call fails
     */
    public function getProductPriceById(int $productId): float
    {
        $queryString = http_build_query([
            'display' => '[id,price]',
            'filter[id]' => $productId,
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

        $basePrice = (float) ($products[0]['price'] ?? 0.0);
        // Apply 22% VAT — consistent with ProductManipulation::calculateFullPrice()
        return round($basePrice * 1.22, 2);
    }

    /**
     * Update cart
     * @param array $product
     * @param string $customerId
     * @return HttpServiceInterface
     */
    public function updateCart(array $product, string $customerId, bool $isGuest = false): HttpServiceInterface
    {
        $customerId = $this->decodeId($customerId, 'customer');

        $this->httpService->setUrl("/carts?ws_key={$this->httpService->getConfig()->apikey}");

        //create a payload
        $products = [
            'id_product' => (int) $product['productId'],
            'id_product_attribute' => (int) $product['productAttributeId'],
            'quantity' => (int) $product['qty'] ?? 1
        ];

        if($isGuest) {
            $payload = CartEntity::create([
                'products' => [$products],
                'id_guest' => $customerId
            ], $this);
        } else {
            $payload = CartEntity::create([
                'products' => [$products],
                'id_customer' => $customerId
            ], $this);
        }

        try {
            $response = $this->httpService->invoke('POST', [
                'cart' => $payload->generatePayload()->toArray()
            ]);
        } catch (\Exception $e) {
            Log::error("Exception occurred while updating cart for customer {$payload->getId()}" . $e->getMessage());
        }

        if($response->failed()) {
            Log::error("Failed to update cart for customer {$payload->getId()}, code:" . $response->getHttpCode() . ", body: " . $response->getBody());
            return $response;
        }

        return $response;
    }

    public function getFeaturedCoupons(int $limit = 6): array
    {
        $rows = $this->getCartRules();
        if (empty($rows)) {
            return [];
        }

        $today = date('Y-m-d H:i:s');
        $featured = array_values(array_filter($rows, static function (array $row) use ($today): bool {
            $isActive = !isset($row['active']) || (bool) $row['active'] === true;
            $hasQuantity = !isset($row['quantity']) || (int) $row['quantity'] > 0;
            $isDateValid = empty($row['date_to']) || (string) $row['date_to'] >= $today;
            return $isActive && $hasQuantity && $isDateValid;
        }));

        return array_slice($featured, 0, max(1, $limit));
    }

    public function getCouponDetail(string $code): ?array
    {
        $rows = $this->getCartRules([
            'code' => $code,
        ]);

        if (empty($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (isset($row['code']) && strcasecmp((string) $row['code'], $code) === 0) {
                return $row;
            }
        }

        return $rows[0];
    }

    public function validateCoupon(string $code, string $cartId, ?string $customerId = null, ?string $guestId = null): ?array
    {
        $query = [
            'code' => $code,
            'id_cart' => $this->decodeId($cartId, 'cart'),
        ];

        if ($customerId !== null) {
            $query['id_customer'] = $this->decodeId($customerId, 'customer');
        }

        if ($guestId !== null) {
            $query['id_guest'] = $this->decodeId($guestId, 'guest');
        }

        $data = $this->invokeCartRules($query);
        if ($data === null) {
            return null;
        }

        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<int, array<string, mixed>>
     */
    private function getCartRules(array $queryParams = []): array
    {
        $data = $this->invokeCartRules($queryParams);
        if ($data === null) {
            return [];
        }

        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        if (isset($data['cart_rules']) && is_array($data['cart_rules'])) {
            return array_values(array_filter($data['cart_rules'], 'is_array'));
        }

        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }

        return [];
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>|null
     */
    private function invokeCartRules(array $queryParams): ?array
    {
        $queryParams['ws_key'] = $this->httpService->getConfig()->apikey;
        $queryString = http_build_query($queryParams);
        $this->httpService->setUrl("/cart_rules?{$queryString}");

        try {
            $response = $this->httpService->invoke('GET');
        } catch (\Exception $e) {
            Log::error("Exception occurred while retrieving cart rules: " . $e->getMessage());
            return null;
        }

        if ($response->failed()) {
            Log::error("Failed to retrieve cart rules, code:" . $response->getHttpCode() . ", body: " . $response->getBody());
            return null;
        }

        $data = $response->toArray();
        return is_array($data) ? $data : null;
    }

}
