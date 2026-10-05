<?php
declare(strict_types=1);

namespace PS\Webservice\Traits;

use PS\Webservice\Domain\Entities\CartRuleEntity;
use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Domain\Entities\OrderEntity;
use PS\Webservice\Domain\Entities\ProductEntity;
use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Service\PS\Order as OrderService;

trait Order
{
    use UseCache;
    protected OrderSession $orderSession;
    protected int $carrierId;
    private OrderService $orderService;

    public function makeOrder(OrderEntity $payload, OrderService $orderService, array $serverCartProducts = [], array $serverCart = []): OrderSession
    {

        $this->orderService = $orderService;
        $orderSession = OrderSession::create([
            'cart_id' => $payload->id_cart,
            'id_customer' => $payload->id_customer ?? null,
            'id_guest' => $payload->id_guest ?? null,
            'id_carrier' => $payload->id_carrier,
            'expires_at' => $payload->expires_at ?? time() + 3600,
            'recovery_attempt' => $payload->recovery_attempt ?? false,
            'customer' => CustomerEntity::create([
                'id' => $payload->customer['id_customer'] ?? null,
                'email' => $payload->customer['email'] ?? throw new \InvalidArgumentException('Customer email is required'),
                'firstname' => $payload->customer['firstname'] ?? null,
                'lastname' => $payload->customer['lastname'] ?? null,
                'phone' => $payload->customer['phone'] ?? null,
                'delivery_address' => $payload->customer['delivery_address'] ?? null,
                'newsletter' => $payload->customer['newsletter'] ?? false,
                'invoice_address' => $payload->customer['invoice_address'] ?? $payload->customer['delivery_address'],
            ], $this->orderService)
        ], $this->orderService);
        $this->orderSession = $orderSession;

        // Populate the session before evaluating discounts and the free-shipping threshold.
        foreach ($serverCartProducts as $product) {
            $this->addProduct($product);
        }
        if (!empty($serverCart['cart_rules'])) {
            $this->orderSession->applyCouponCart($serverCart);
        } else {
            $this->manageCartRules($payload);
        }
        $this->tags(['order-session'])->setToCache($payload->id_cart, $orderSession, 36 * 60);

        return $orderSession;
    }

    public function addProduct(array $product)
    {   
        $this->orderSession->addCartLineItem($product);
    }

    public function getProducts(): array
    {
        return $this->orderSession->getLineItems();
    }

    public function manageCartRules(OrderEntity $payload)
    {
        $cartRules = $payload->getCartRules();
        $carrierDetails = $this->orderService->getCarrierDetail($payload->id_carrier);
        if (is_null($carrierDetails)) {
            throw new \InvalidArgumentException('Invalid carrier ID: ' . $payload->id_carrier);
        }

        //check for free shipping cart rule
        if ($this->checkForFreeShippingCartRule($cartRules) === false) {
            $this->orderSession->addCarrierLineItem(
                name: $carrierDetails->name,
                quantity: 1,
                price: (float) $carrierDetails->price_with_tax,
                type: 'carrier'
            );
        }
    }

    private function checkForFreeShippingCartRule(CartRuleEntity $cartRules): bool
    {
        foreach ($cartRules->toArray() as $cartRule) {
            if ($cartRule['rule']['rule'] == "free-shipping" && $cartRule['rule']['conditions']['minimum-spend'] <= $this->orderSession->total()) {
                return true;
            }
        }

        return false;
    }

}
