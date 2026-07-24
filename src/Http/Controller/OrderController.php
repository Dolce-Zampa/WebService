<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use PS\Webservice\Domain\Entities\CartRuleEntity;
use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Domain\Object\ConfirmOrderSession;
use PS\Webservice\Domain\Object\Discount;
use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Service\PS\Order;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class OrderController extends CartController
{
    private const ORDER_STATE_PAYMENT_ACCEPTED = 2;
    private Order $orderService;

    public function __construct(Order $orderService)
    {
        $this->orderService = $orderService;
    }

    public function orderHistory(Request $request, Response $response, array $argv): Response
    {
        $customerId = $argv['customerId'];
        $orders = $this->orderService->getOrderListFromUserId($customerId);

        if (is_null($orders)) {
            return response([], 404);
        }

        return response($orders);

    }

    public function getOrder(Request $request, Response $response, array $argv): Response
    {
        $orderId = $argv['orderId'];
        $cartList = $this->orderService->orderDetails($orderId);

        if (is_null($cartList)) {
            return response([], 404);
        }

        return response($cartList);

    }

    public function confirmOrder(Request $request, Response $response, array $argv): Response
    {
        $payload = $request->getParsedBody();
        $customerId = $payload['id_customer'] ?? null;
        $guestId = $payload['id_guest'] ?? null;

        if (!is_array($payload)) {
            return response([
                'success' => false,
                'status' => 'invalid_payload',
                'error' => 'Invalid payload format'
            ], 400);
        }

        $cartId = $payload['id_cart'] ?? null;
        if ($cartId === null) {
            return response([
                'success' => false,
                'status' => 'invalid_cart_id',
                'error' => 'Valid cart ID is required'
            ], 400);
        }

        try {
            $order = $this->orderService->getOrderByCartId($cartId, $customerId, $guestId);
            if ($order === null) {
                return response([
                    'success' => false,
                    'status' => 'pending',
                    'error' => 'Order not found'
                ], 202);
            }

            $orderData = $order->toArray();
            if (!array_key_exists('current_state', $orderData)) {
                return response([
                    'success' => false,
                    'status' => 'invalid_order_data',
                    'error' => 'Invalid order state data'
                ], 500);
            }

            $currentState = (int) $orderData['current_state'];
            $isPaymentAccepted = $currentState === self::ORDER_STATE_PAYMENT_ACCEPTED;

            return response([
                'success' => $isPaymentAccepted,
                'order_reference' => $orderData['reference'] ?? null,
                'order' => $orderData
            ]);
        } catch (\Exception $e) {
            return response([
                'success' => false,
                'status' => 'error',
                'error' => 'Failed to verify order: ' . $e->getMessage()
            ], 500);
        }
    }

    private function currentCartRule(): array
    {
        $cartRuleSettings = file_get_contents(__DIR__ . '/../../../storage/configs/cart_rules.json');
        $cartRules = CartRuleEntity::create(json_decode($cartRuleSettings, true), $this->orderService);
        return $cartRules->toArray() ?? [];
    }

    public function createOrder(Request $request, Response $response, array $argv): Response
    {
        $payload = $request->getParsedBody();

        // check payment method if cod 
        $paymentMethod = $payload['payment_method'];

        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid payload format', 400);
        }

        // Ownership check: require customer or guest identification — never trust anonymous cart access
        $customerId = isset($payload['id_customer']) ? $payload['id_customer'] : null;
        $guestId = isset($payload['id_guest']) ? $payload['id_guest'] : null;

        $currentCartRule = $this->currentCartRule();
        $cartRules = CartRuleEntity::create($currentCartRule, $this->orderService) ?? [];

        if ($customerId === null && $guestId === null) {
            return response(['error' => 'Customer ID or guest ID is required'], 403);
        }

        $cart = $this->orderService->getCartFromId($payload['id_cart'], $customerId, $guestId);
        if (is_null($cart)) {
            return response([], 404);
        }

        // Create payment session
        try {
            $paymentService = $this->initializePaymentService($paymentMethod);

            //recuperiamo il corriere scelto dal cliente per aggiungerlo alla sessione di pagamento
            $carrierId = $payload['id_carrier'] ?? null;
            if (is_null($carrierId)) {
                throw new \InvalidArgumentException('Carrier ID is required for payment session');
            }

            $carrierDetails = $this->orderService->getCarrierDetail($carrierId);
            if (is_null($carrierDetails)) {
                throw new \InvalidArgumentException('Invalid carrier ID: ' . $carrierId);
            }

            $orderSession = \PS\Webservice\Domain\Object\OrderSession::create([
                'success_url' => $_ENV['STRIPE_SUCCESS_URL'] ?? '',
                'cancel_url' => $_ENV['STRIPE_CANCEL_URL'] ?? '',
                'cart_id' => $payload['id_cart'],
                'id_customer' => $payload['id_customer'] ?? null,
                'id_guest' => $payload['id_guest'] ?? null,
                'id_carrier' => $carrierId,
                'customer' => CustomerEntity::create([
                    'id' => $payload['id_customer'] ?? null,
                    'email' => $payload['customer']['email'] ?? null,
                    'firstname' => $payload['customer']['firstname'] ?? null,
                    'lastname' => $payload['customer']['lastname'] ?? null,
                    'phone' => $payload['customer']['phone'] ?? null,
                    'delivery_address' => $payload['delivery_address'] ?? null,
                    'newsletter' => $payload['customer']['newsletter'] ?? false,
                    'invoice_address' => $payload['invoice_address'] ?? $payload['delivery_address'],
                ], $this->orderService)
            ], $this->orderService);


            // Server-side price validation: fetch each product price directly from the catalog.
            // Never use prices from the cart payload or any frontend-supplied value.
            foreach ($cart->toArray()['products'] ?? [] as $product) {
                $productId = (int) $product['id_product'];
                // $serverPrice = $this->orderService->getProductPriceById($productId); // not correct
                $serverPrice = $product['price_wt'];
                $orderSession->addLineItem(
                    name: $product['name'] ?? "Product #{$productId}",
                    quantity: (int) $product['quantity'],
                    price: $serverPrice
                );
            }

            // add discount if there are cart rules applied to this cart - in a real implementation we would need to check if the cart rules are still valid and applicable to this cart before applying them to the payment session
            foreach ($cartRules->toArray() as $rule) {
                if (isset($payload['cart_rules'])) {
                    foreach ($payload['cart_rules'] as $clientRule) {
                        $this->manageDiscounts($orderSession, $clientRule);
                    }
                }
            }

            //check for free shipping cart rule
            if ($this->checkForFreeShippingCartRule($cartRules, $orderSession) === false) {
                $orderSession->addLineItem(
                    name: $carrierDetails->name,
                    quantity: 1,
                    price: (float) $carrierDetails->price_with_tax,
                    type: 'carrier'
                );
            }

            $checkoutUrl = $paymentService->createPaymentSession($orderSession);

            return response([
                'order' => $cart->toArray(),
                'payment_url' => $checkoutUrl
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response([
                'order' => $cart->toArray(),
                'error' => $e->getMessage()
            ], 400);
        } catch (\Exception $e) {
            return response([
                'order' => $cart->toArray(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

    protected function manageDiscounts(OrderSession $orderSession, array $cartRules): void
    {
        $orderSession->addDiscount(new Discount(
            name: $cartRules['code'],
            amount_off: $this->mathReduction($orderSession, $cartRules['reduction_percent'] ?? null, $cartRules['reduction_amount'] ?? null),
            code: $cartRules['code'],
            duration: 'once'
        ));
    }

    /**
     * @deprecated 
     */
    private function mathReduction(OrderSession $currentOrder, ?float $reductionPercent = null, ?float $reductionAmount = null): float
    {
        return $reductionPercent;

        $total = $currentOrder->total();

        if (!empty($reductionPercent)) {
            $reduction = ($total * ($reductionPercent / 100));
        }

        if (!empty($reductionAmount)) {
            $reduction = $reductionAmount;
        }

        return max($reduction, 0);
    }

    private function checkForFreeShippingCartRule(CartRuleEntity $cartRules, OrderSession $totalToPay): bool
    {
        foreach ($cartRules->toArray() as $cartRule) {
            if ($cartRule['rule']['rule'] == "free-shipping" && $cartRule['rule']['conditions']['minimum-spend'] <= $totalToPay->total()) {
                return true;
            }
        }

        return false;
    }

    public function initiatePayment(Request $request, Response $response, array $argv): Response
    {
        $payload = $request->getParsedBody();

        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid payload format', 400);
        }

        // Ownership check: require customer or guest identification
        $customerId = isset($payload['id_customer']) ? $payload['id_customer'] : null;
        $guestId = isset($payload['id_guest']) ? $payload['id_guest'] : null;

        if ($customerId === null && $guestId === null) {
            return response(['error' => 'Customer ID or guest ID is required'], 403);
        }

        if (!isset($payload['id_cart'])) {
            return response(['error' => 'Cart ID is required'], 400);
        }

        $cart = $this->orderService->getCartFromId($payload['id_cart'], $customerId, $guestId);
        if (is_null($cart)) {
            return response(['error' => 'Cart not found or access denied'], 404);
        }

        try {
            $paymentService = $this->initializePaymentService($payload['paymentMethod'] ?? 'stripe');
            $orderSession = \PS\Webservice\Domain\Object\OrderSession::create([
                'success_url' => $payload['success_url'] ?? $_ENV['STRIPE_SUCCESS_URL'] ?? '',
                'cancel_url' => $payload['cancel_url'] ?? $_ENV['STRIPE_CANCEL_URL'] ?? '',
                'cart_id' => $payload['id_cart'],
            ], $this->orderService);

            // Server-side price validation: prices are fetched from the product catalog,
            // never from the frontend payload.
            foreach ($cart->toArray()['products'] ?? [] as $product) {
                $productId = (int) $product['id_product'];
                $serverPrice = $this->orderService->getProductPriceById($productId);

                $orderSession->addLineItem(
                    name: $product['name'] ?? "Product #{$productId}",
                    quantity: (int) $product['quantity'],
                    price: $serverPrice
                );
            }

            $checkoutUrl = $paymentService->createPaymentSession($orderSession);

            return response(['url' => $checkoutUrl], 200);
        } catch (\Exception $e) {
            return response(['error' => $e->getMessage()], 500);
        }
    }

    private function initializePaymentService(string $paymentMethod): \PS\Webservice\Service\Auth\Payments\PaymentGatewayInterface
    {
        switch ($paymentMethod) {
            case 'stripe':
                $apiKey = $_ENV['STRIPE_API_KEY'] ?? throw new \RuntimeException('STRIPE_API_KEY not configured');
                return \PS\Webservice\Service\Auth\Payments\PaymentService::setApiKey($apiKey);
            case 'cod':
                return \PS\Webservice\Service\Auth\Payments\CodPaymentService::setApiKey('');
            default:
                throw new \InvalidArgumentException('Unsupported payment method: ' . $paymentMethod);
        }
    }

}
