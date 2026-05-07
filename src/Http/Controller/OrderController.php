<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use PS\Webservice\Service\PS\Order;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class OrderController extends CartController {
    private const ORDER_STATE_PAYMENT_ACCEPTED = 2;

    private Order $orderService;

    public function __construct(Order $orderService)
    {
        $this->orderService = $orderService;
    }

    public function orderHistory(Request $request, Response $response, array $argv): Response
    {
        $customerId = (int) $argv['customerId'];
        $cartList = $this->orderService->getOrderListFromUserId($customerId);
        
        if(is_null($cartList)) {
            return response([], 404);
        }

        return response($cartList);

    }

    public function getOrder(Request $request, Response $response, array $argv): Response
    {
        $orderId = (int) $argv['orderId'];
        $cartList = $this->orderService->orderDetails($orderId);
        
        if(is_null($cartList)) {
            return response([], 404);
        }

        return response($cartList);

    }
    
    public function confirmOrder(Request $request, Response $response, array $argv): Response
    {
        $payload = $request->getParsedBody();

        if (!is_array($payload)) {
            return response([
                'success' => false,
                'error' => 'Invalid payload format'
            ], 400);
        }

        $cartId = filter_var($payload['id_cart'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($cartId === false) {
            return response([
                'success' => false,
                'error' => 'Valid cart ID is required'
            ], 400);
        }

        try {
            $order = $this->orderService->getOrderByCartId($cartId);
            if ($order === null) {
                return response([
                    'success' => false,
                    'status' => 'pending'
                ], 202);
            }

            $orderData = $order->toArray();
            if (!array_key_exists('current_state', $orderData)) {
                return response([
                    'success' => false,
                    'error' => 'Invalid order state data'
                ], 500);
            }

            $currentState = (int) $orderData['current_state'];
            $isPaymentAccepted = $currentState === self::ORDER_STATE_PAYMENT_ACCEPTED;

            return response([
                'success' => $isPaymentAccepted,
                'order' => $orderData
            ]);
        } catch (\Exception $e) {
            return response([
                'success' => false,
                'error' => 'Failed to verify order: ' . $e->getMessage()
            ], 500);
        }
    }

    public function createOrder(Request $request, Response $response, array $argv): Response
    {
        $payload = $request->getParsedBody();

        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid payload format', 400);
        }

        // Ownership check: require customer or guest identification — never trust anonymous cart access
        $customerId = isset($payload['id_customer']) ? (int) $payload['id_customer'] : null;
        $guestId = isset($payload['id_guest']) ? (int) $payload['id_guest'] : null;

        if ($customerId === null && $guestId === null) {
            return response(['error' => 'Customer ID or guest ID is required'], 403);
        }
        
        $cart = $this->orderService->getCartFromId((int) $payload['id_cart'], $customerId, $guestId);
        if(is_null($cart)) {
            return response([], 404);
        }

        // Create payment session
        try {
            $paymentService = $this->initializePaymentService($payload['paymentMethod'] ?? 'stripe');

            //recuperiamo il corriere scelto dal cliente per aggiungerlo alla sessione di pagamento
            $carrierId = $payload['id_carrier'] ?? null;
            if(is_null($carrierId)) {
                throw new \InvalidArgumentException('Carrier ID is required for payment session');
            }

            $carrierDetails = $this->orderService->getCarrierDetail($carrierId);
            if(is_null($carrierDetails)) {
                throw new \InvalidArgumentException('Invalid carrier ID: ' . $carrierId);
            }

            $orderSession = \PS\Webservice\Domain\Object\OrderSession::create([
                'success_url' => $_ENV['STRIPE_SUCCESS_URL'] ?? '',
                'cancel_url' => $_ENV['STRIPE_CANCEL_URL'] ?? '',
                'cart_id' => $payload['id_cart'],
                'id_customer' => $payload['id_customer'] ?? null,
                'id_guest' => $payload['id_guest'] ?? null,
                'id_carrier' => $carrierId,
            ], $this->orderService);

            $orderSession->addLineItem(
                name: $carrierDetails->name,
                quantity: 1,
                price: (float) $carrierDetails->price_with_tax
            );
            
            // Server-side price validation: fetch each product price directly from the catalog.
            // Never use prices from the cart payload or any frontend-supplied value.
            foreach ($cart->toArray()['products'] ?? [] as $product) {
                $productId = (int) $product['id_product'];
                $serverPrice = $this->orderService->getProductPriceById($productId);

                $orderSession->addLineItem(
                    name: $product['name'] ?? "Product #{$productId}",
                    quantity: (int)$product['quantity'],
                    price: $serverPrice
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

    public function initiatePayment(Request $request, Response $response, array $argv): Response
    {
        $payload = $request->getParsedBody();

        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid payload format', 400);
        }

        // Ownership check: require customer or guest identification
        $customerId = isset($payload['id_customer']) ? (int) $payload['id_customer'] : null;
        $guestId = isset($payload['id_guest']) ? (int) $payload['id_guest'] : null;

        if ($customerId === null && $guestId === null) {
            return response(['error' => 'Customer ID or guest ID is required'], 403);
        }

        if (!isset($payload['id_cart'])) {
            return response(['error' => 'Cart ID is required'], 400);
        }

        $cart = $this->orderService->getCartFromId((int) $payload['id_cart'], $customerId, $guestId);
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

    private function initializePaymentService(string $paymentMethod): \PS\Webservice\Service\Payments\PaymentGatewayInterface
    {
        switch ($paymentMethod) {
            case 'stripe':
                $apiKey = $_ENV['STRIPE_API_KEY'] ?? throw new \RuntimeException('STRIPE_API_KEY not configured');
                return \PS\Webservice\Service\Payments\PaymentService::setApiKey($apiKey);
            default:
                throw new \InvalidArgumentException('Unsupported payment method: ' . $paymentMethod);
        }
    }

}
