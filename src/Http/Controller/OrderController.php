<?php
declare(strict_types=1);

namespace DolzeZampa\WS\Http\Controller;

use DolzeZampa\WS\Domain\Entities\CustomerEntity;
use DolzeZampa\WS\Domain\Object\ConfirmOrderSession;
use DolzeZampa\WS\Service\PS\Order;
use PaymentGatewayInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class OrderController extends CartController {

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
            throw new \InvalidArgumentException('Invalid payload format', 400);
        }

        try {
            $confirmSession = ConfirmOrderSession::create($payload, $this->orderService);
            $confirmSession->setCustomer(
                CustomerEntity::create([
                    'email' => $payload['customer']['email'],
                    'firstname' => $payload['customer']['firstname'],
                    'lastname' => $payload['customer']['lastname'],
                    'newsletter' => $payload['customer']['newsletter'] ?? false,
                    'delivery_address' => $payload['customer']['delivery_address']
                ], $this->orderService)
            );
            
            $errors = $confirmSession->validate();
            if (!empty($errors)) {
                return response([
                    'success' => false,
                    'errors' => $errors
                ], 400);
            }

            $order = $this->orderService->confirmOrder($confirmSession);
            
            return response([
                'success' => true,
                'order' => $order->toArray()
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        } catch (\Exception $e) {
            return response([
                'success' => false,
                'error' => 'Failed to confirm order: ' . $e->getMessage()
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
            $orderSession = \DolzeZampa\WS\Domain\Object\OrderSession::create([
                'success_url' => $_ENV['STRIPE_SUCCESS_URL'] ?? '',
                'cancel_url' => $_ENV['STRIPE_CANCEL_URL'] ?? '',
                'cart_id' => $payload['id_cart'],
            ], $this->orderService);

            //recuperiamo il corriere scelto dal cliente per aggiungerlo alla sessione di pagamento
            $carrierId = $payload['id_carrier'] ?? null;
            if(is_null($carrierId)) {
                throw new \InvalidArgumentException('Carrier ID is required for payment session');
            }

            $carrierDetails = $this->orderService->getCarrierDetail($carrierId);
            if(is_null($carrierDetails)) {
                throw new \InvalidArgumentException('Invalid carrier ID: ' . $carrierId);
            }

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
            $orderSession = \DolzeZampa\WS\Domain\Object\OrderSession::create([
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

    private function initializePaymentService(string $paymentMethod): \DolzeZampa\WS\Service\Payments\PaymentGatewayInterface
    {
        switch ($paymentMethod) {
            case 'stripe':
                $apiKey = $_ENV['STRIPE_API_KEY'] ?? throw new \RuntimeException('STRIPE_API_KEY not configured');
                return \DolzeZampa\WS\Service\Payments\PaymentService::setApiKey($apiKey);
            default:
                throw new \InvalidArgumentException('Unsupported payment method: ' . $paymentMethod);
        }
    }

}