<?php
declare(strict_types=1);

namespace PS\Webservice\Traits;

use PS\Webservice\Domain\Entities\CartRuleEntity;
use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Domain\Entities\OrderEntity;
use PS\Webservice\Domain\Entities\ProductEntity;
use PS\Webservice\Domain\Object\Discount;
use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Service\PS\Order as OrderService;

trait Order
{
    use UseCache;
    protected OrderSession $orderSession;
    protected int $carrierId;
    private OrderService $orderService;

    public function makeOrder(OrderEntity $payload, OrderService $orderService): OrderSession
    {
        //save customer into cache 
        $this->tags(['customer-order'])->setToCache($payload->customer['email'], $payload->customer, 36 * 60);

        $this->orderService = $orderService;
        $orderSession = OrderSession::create([
            'cart_id' => $payload->id_cart,
            'id_customer' => $payload->customer['id_customer'] ?? null,
            'id_guest' => $payload->customer['id_guest'] ?? null,
            'id_carrier' => $payload->id_carrier,
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

        $this->manageCartRules($payload);

        return $orderSession;
    }

    public function addProduct(array $product)
    {
        $productId = (int) $product['id_product'];
        $serverPrice = $this->orderService->getProductPriceById($productId);
        $product['id'] = $productId; // Ensure the product array has the correct ID for ProductEntity creation
        $this->orderSession->addLineItem(
            product: ProductEntity::create($product, $this->orderService),
            quantity: (int) $product['quantity'],
            price: $serverPrice
        );
    }

    private function manageDiscounts(array $cartRules): void
    {
        $this->orderSession->addDiscount(new Discount(
            name: $cartRules['code'],
            amount_off: $this->mathReduction($cartRules['reduction_percent'] ?? null, $cartRules['reduction_amount'] ?? null),
            code: $cartRules['code'],
            duration: 'once'
        ));
    }

    public function manageCartRules(OrderEntity $payload)
    {
        $cartRules = $payload->getCartRules();
        $carrierDetails = $this->orderService->getCarrierDetail($payload->id_carrier);
        if (is_null($carrierDetails)) {
            throw new \InvalidArgumentException('Invalid carrier ID: ' . $payload->id_carrier);
        }

        // add discount if there are cart rules applied to this cart - in a real implementation we would need to check if the cart rules are still valid and applicable to this cart before applying them to the payment session
        //FIXME: maybe there are a bug
        foreach ($cartRules->toArray() as $rule) {
            if (isset($payload->cart_rules)) {
                foreach ($payload->cart_rules as $clientRule) {
                    $this->manageDiscounts($clientRule);
                }
            }
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

    /**
     * @deprecated 
     */
    private function mathReduction(?float $reductionPercent = null, ?float $reductionAmount = null): float
    {
        return $reductionPercent;

        $total = $this->orderSession->total();

        if (!empty($reductionPercent)) {
            $reduction = ($total * ($reductionPercent / 100));
        }

        if (!empty($reductionAmount)) {
            $reduction = $reductionAmount;
        }

        return max($reduction, 0);
    }
}