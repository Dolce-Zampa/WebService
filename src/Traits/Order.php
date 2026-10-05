<?php
declare(strict_types=1);

namespace PS\Webservice\Traits;

use PS\Webservice\Domain\Entities\CartRuleEntity;
use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Domain\Entities\OrderEntity;
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
            'payment_module' => $payload->payment_module ?? $serverCart['payment_module'] ?? null,
            'create_account' => $payload->create_account ?? false,
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
        $freeShipping = $this->checkForFreeShippingCartRule($payload->getCartRules());
        if (!empty($serverCart['cart_rules'])) {
            $this->orderSession->applyCouponCart(
                $freeShipping ? $this->withoutShipping($serverCart) : $serverCart
            );
        } elseif (!$freeShipping) {
            $this->orderSession->addCartShipping($serverCart);
        }
        $this->tags(['order-session'])->setEncryptedToCache($payload->id_cart, $orderSession->toCacheData(), 36 * 60);

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

    private function checkForFreeShippingCartRule(?CartRuleEntity $cartRules): bool
    {
        if ($cartRules === null) {
            return false;
        }

        foreach ($cartRules->toArray() as $cartRule) {
            $rule = $cartRule['rule'] ?? [];
            $conditions = $rule['conditions'] ?? [];
            if (($rule['rule'] ?? null) === 'free-shipping'
                && is_numeric($conditions['minimum-spend'] ?? null)
                && (float) $conditions['minimum-spend'] <= $this->orderSession->total()) {
                return true;
            }
        }

        return false;
    }

    /** Removes the carrier charge from API totals when local free-shipping rules apply. */
    private function withoutShipping(array $cart): array
    {
        $shipping = $cart['totals']['shipping_tax_incl'] ?? $cart['total_shipping_tax_incl'] ?? 0;
        if (!is_numeric($shipping) || (float) $shipping < 0) {
            throw new \InvalidArgumentException('Cart shipping total is required and must be valid.');
        }

        if (isset($cart['totals']['shipping_tax_incl'])) {
            $cart['totals']['shipping_tax_incl'] = 0.0;
        }
        if (isset($cart['total_shipping_tax_incl'])) {
            $cart['total_shipping_tax_incl'] = 0.0;
        }
        if (isset($cart['totals']['grand_total_tax_incl'])) {
            $cart['totals']['grand_total_tax_incl'] = (float) $cart['totals']['grand_total_tax_incl'] - (float) $shipping;
        }

        return $cart;
    }

}
