<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PS;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Domain\Entities\CartEntity;
use PS\Webservice\Domain\Entities\OrderEntity;
use PS\Webservice\Domain\Models\CartStorage;
use PS\Webservice\Domain\Object\ConfirmOrderSession;
use PS\Webservice\Domain\Object\Discount;
use PS\Webservice\Facades\JsonDataStorage;
use PS\Webservice\Service\HttpServiceInterface;
use PS\Webservice\Service\PS\Cart;
use PS\Webservice\Service\PS\PrestashopServiceInterface;
use PS\Webservice\Traits\UseCache;
use PS\Webservice\Traits\UuidGenerator;

class Order extends Cart implements PrestashopServiceInterface
{

    use UuidGenerator, UseCache;

    public function __construct(HttpServiceInterface $httpService)
    {
        parent::__construct($httpService);
    }

    public function getOrderByCartId(int|string $cartId, int|string|null $customerId = null, int|string|null $guestId = null): ?OrderEntity
    {
        if (is_string($cartId)) {
            $cartId = $this->decodeId($cartId, 'cart');
        }

        // find reference order from cache
        $cachedOrder = JsonDataStorage::carts()->createQuery()->where('id_cart', (string) $cartId)->fetchAll();
        if (empty($cachedOrder)) {
            Log::debug("Order retrieved from cache for cart {$cartId}");
            throw new \RuntimeException("Order retrieved from cache for cart {$cartId}");
        }

        $queryString = http_build_query([
            'reference' => $cachedOrder[0]['reference'],
        ]);

        $this->httpService->setUrl("/orders?{$queryString}");

        try {
            $response = $this->httpService->invoke('GET');
            $data = $response->toArray();
            if ($response->failed() || empty($data['data'])) {
                return null;
            }
            return OrderEntity::create($data['data']['order'], $this);
        } catch (\Exception $e) {
            Log::error("Exception occurred while retrieving order for cart {$cartId}: " . $e->getMessage());
            return null;
        }
    }

    public function getOrderListFromUserId(?string $customerId = null): ?array
    {
        $queryString = http_build_query([
            'id_customer' => $this->decodeId($customerId, 'customer'),
        ]);

        $this->httpService->setUrl("/orders?{$queryString}");

        try {
            $response = $this->httpService->invoke('GET');
        } catch (\Exception $e) {
            Log::error("Exception occurred while retrieving orders for customer {$customerId}" . $e->getMessage());
            return null;
        }

        $data = $response->toArray();
        if ($response->getHttpCode() >= 400 || !isset($data['data']['orders']) || !is_array($data['data']['orders'])) {
            return null;
        }

        $orders = [];
        foreach ($data['data']['orders'] as $row) {
            if (is_array($row)) {
                $orders[] = OrderEntity::create($row, $this)->toArray();
            }
        }

        return $orders;
    }

    public function orderDetails(string $orderId): ?OrderEntity
    {
        $queryString = http_build_query([
            'id_order' => $this->decodeId($orderId, 'order'),
        ]);
        $this->httpService->setUrl("/orders?{$queryString}");

        try {
            $response = $this->httpService->invoke('GET');
        } catch (\Exception $e) {
            Log::error("Exception occurred while retrieving order with id {$orderId}" . $e->getMessage());
            return null;
        }

        $orderData = $response->toArray()['data']['order'] ?? null;
        $orderData['customer'] = $response->toArray()['data']['customer'] ?? [];

        return OrderEntity::create($orderData, $this);
    }

    public function newOrder(CartEntity $cart): CartEntity
    {
        $this->httpService->setUrl("/orders");

        try {
            $response = $this->httpService->invoke('POST', $cart->generatePayload());
        } catch (\Exception $e) {
            Log::error("Exception occurred while creating order for cart {$cart->getId()}: " . $e->getMessage());
            throw new \RuntimeException("Failed to create order: " . $e->getMessage());
        }

        return $cart;
    }

    public function confirmOrder(ConfirmOrderSession $confirmSession): void
    {
        $errors = $confirmSession->validate();
        if (!empty($errors)) {
            throw new \InvalidArgumentException('Validation failed: ' . implode(', ', $errors));
        }

        $this->httpService->setUrl("/orders?ws_key={$this->httpService->getConfig()->apikey}");

        $orderData = [
            'id_cart' => $confirmSession->id_cart,
            'payment_module' => $confirmSession->payment_module,
            'payment_label' => $confirmSession->payment_label,
            'id_order_state' => $confirmSession->id_order_state,
            'amount_paid' => $confirmSession->amount_paid,
            'id_carrier' => $confirmSession->id_carrier,
            'coupon_code' => $confirmSession->coupon_code,
        ];

        // Add customer/guest identification
        if ($confirmSession->id_customer !== null) {
            $orderData['id_customer'] = $confirmSession->id_customer;
        } elseif ($confirmSession->id_guest !== null) {
            $orderData['id_guest'] = $confirmSession->id_guest;
        }

        // Add guest registration data if provided
        if ($confirmSession->shouldCreateAccount()) {
            $orderData['create_account'] = true;
            $orderData['email'] = $confirmSession->getCustomer()->email;
            $orderData['firstname'] = $confirmSession->getCustomer()->firstname;
            $orderData['lastname'] = $confirmSession->getCustomer()->lastname;

            if ($confirmSession->getCustomer()->password !== null) {
                $orderData['password'] = $confirmSession->getCustomer()->password;
            }
        } elseif ($confirmSession->getCustomer()->email !== null) {
            $orderData['email'] = $confirmSession->getCustomer()->email;
            $orderData['firstname'] = $confirmSession->getCustomer()->firstname;
            $orderData['lastname'] = $confirmSession->getCustomer()->lastname;
        }

        // Add addresses if provided
        if ($confirmSession->getDeliveryAddress() !== null) {
            $orderData['delivery_address'] = $confirmSession->getDeliveryAddress();
        }

        if ($confirmSession->getInvoiceAddress() !== null) {
            $orderData['invoice_address'] = $confirmSession->getInvoiceAddress();
        }

        try {
            $response = $this->httpService->invoke('POST', $orderData);

            $dataResponse = $response->toArray();
            if ($response->failed() || $dataResponse['success'] === false) {
                throw new \RuntimeException("Order confirmation failed: " . $response->getBody() . ' code: ' . $response->getHttpCode());
            }

            Log::debug("Order confirmation response for cart {$confirmSession->id_cart}: " . json_encode($dataResponse) . ' code: ' . $response->getHttpCode());

            // setup reference in storage for later retrieval in getOrderByCartId
            JsonDataStorage::carts()->insert(
                new CartStorage($dataResponse['data']['order'])
            );

        } catch (\Exception $e) {
            Log::error("Exception occurred while confirming order for cart {$confirmSession->id_cart}: " . $e->getMessage());
            throw new \RuntimeException("Failed to confirm order: " . $e->getMessage());
        }
    }

    public function findExistingStripeCoupon(string $couponName): ?\Stripe\Coupon
    {
        try {
            $coupons = \Stripe\Coupon::all(['limit' => 100]);

            foreach ($coupons->data as $coupon) {
                if ($coupon->name === $couponName) {
                    return $coupon;
                }
            }
            return null;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            return null;
        }
    }

    public function createCouponCode(Discount $discount): string
    {
        // 2. Se non esiste, lo crei al volo
        $stripeCoupon = \Stripe\Coupon::create([
            'name' => $discount->name,
            // Usi 'percent_off' o 'amount_off' in base al tuo sconto
            'percent_off' => $discount->amount_off,
            'duration' => $discount->duration, // 'once', 'forever', 'repeating'
        ]);
        
        return $stripeCoupon->id;
    }

}
