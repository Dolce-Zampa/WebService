<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Domain\Entities\OrderEntity;
use PS\Webservice\Domain\Entities\ProductEntity;
use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Repositories\PrestashopRepository;
use PS\Webservice\Service\Payments\PaymentGatewayInterface;
use PS\Webservice\Service\PS\Order;
use PS\Webservice\Traits\Order as OrderHelper;
use PS\Webservice\Traits\UseCache;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class OrderController extends CartController
{
    use UseCache, OrderHelper;
    private const ORDER_STATE_PAYMENT_ACCEPTED = 2;
    private Order $orderService;

    protected PaymentGatewayInterface $stripeService;

    public function __construct(Order $orderService, PaymentGatewayInterface $stripeService, PrestashopRepository $prestashopRepository)
    {
        $this->cartService = $orderService;
        $this->prestashopRepository = $prestashopRepository;
        $this->stripeService = $stripeService;
        $this->orderService = $orderService;
    }

    public function orderHistory(Request $request, Response $response, array $argv): Response
    {
        $customerId = (int) ($argv['customerId'] ?? 0);
        $authenticatedCustomerId = $this->resolveAuthenticatedCustomerIdOrDenyFromRepository($request, $this->prestashopRepository);
        if ($authenticatedCustomerId instanceof Response) {
            return $authenticatedCustomerId;
        }

        if ($authenticatedCustomerId !== $customerId) {
            return response(['error' => 'Forbidden'], 403);
        }

        $orders = $this->orderService->getOrderListFromUserId((string) $authenticatedCustomerId);

        if (is_null($orders)) {
            return response([], 404);
        }

        return response($orders);

    }

    public function getOrder(Request $request, Response $response, array $argv): Response
    {
        $orderId = $argv['orderId'];
        $authenticatedCustomerId = $this->resolveAuthenticatedCustomerIdOrDenyFromRepository($request, $this->prestashopRepository);
        if ($authenticatedCustomerId instanceof Response) {
            return $authenticatedCustomerId;
        }

        $cartList = $this->orderService->orderDetails((string) $orderId, (string) $authenticatedCustomerId);

        if (is_null($cartList)) {
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
                'status' => 'invalid_payload',
                'error' => 'Invalid payload format'
            ], 400);
        }

        $ownerContext = $this->resolveOwnerContext($request, $payload);
        if ($ownerContext instanceof Response) {
            return $ownerContext;
        }

        $customerId = $ownerContext['customerId'];
        $guestId = $ownerContext['guestId'];

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

    public function createOrder(Request $request, Response $response, array $argv): Response
    {
        $payload = $request->getParsedBody();
        if (!is_array($payload)) {
            return response([
                'success' => false,
                'status' => 'invalid_payload',
                'error' => 'Invalid payload format'
            ], 400);
        }

        $ownerContext = $this->resolveOwnerContext($request, $payload);
        if ($ownerContext instanceof Response) {
            return $ownerContext;
        }

        $customerId = $ownerContext['customerId'];
        $guestId = $ownerContext['guestId'];

        if (!isset($payload['id_cart'])) {
            return response(['error' => 'Cart ID is required'], 400);
        }

        $payload = $this->normalizeOwnerPayload($payload, $ownerContext);
        $cart = $this->orderService->getCartFromId($payload['id_cart'], $customerId, $guestId);
        if (is_null($cart)) {
            return response([], 404);
        }

        // Create payment session
        try {
            $paymentService = $this->stripeService;
            $codes = [];
            if (!empty($payload['coupon_code'])) {
                $codes[] = trim((string) $payload['coupon_code']);
            }
            foreach ($payload['cart_rules'] ?? [] as $rule) {
                if (!is_array($rule) || empty($rule['code'])) {
                    throw new \InvalidArgumentException('Coupon code is required.');
                }
                $codes[] = trim((string) $rule['code']);
            }
            // Recalculate the cart only after persisting the selected carrier. The cart
            // fetched above can still contain shipping for the carrier selected earlier
            // in the storefront, while the checkout payload contains the current one.
            $cart = $this->orderService->prepareCheckoutCart(
                $payload['id_cart'],
                $customerId,
                $guestId,
                (int) $payload['id_carrier'],
                $codes,
                $payload
            );
            $newOrder = OrderEntity::create($payload, $this->orderService);
            $orderSession = $this->makeOrder($newOrder, $this->orderService, $cart->toArray()['products'] ?? [], $cart->toArray());
            if (empty($orderSession->metadata['payment_module'])) {
                throw new \InvalidArgumentException('A payment module must be selected or configured.');
            }

            //save in cache the order session only for 24h
            $this->tags(['order-session'])->setEncryptedToCache(
                $orderSession->metadata['cart_id'],
                $orderSession->toCacheData(),
                24 * 60
            );
            $checkoutUrl = $paymentService->createPaymentSession($orderSession);

            return response([
                'order' => $cart->toArray(),
                'payment_url' => $checkoutUrl
            ], 201);
        } catch (\InvalidArgumentException $e) {
            Log::critical("Error on create order" . $e->getMessage());
            return response([
                'order' => $cart->toArray(),
                'error' => $e->getMessage()
            ], 400);
        } catch (\Exception $e) {
            Log::critical("Error on create order" . $e->getMessage());
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

        $ownerContext = $this->resolveOwnerContext($request, $payload);
        if ($ownerContext instanceof Response) {
            return $ownerContext;
        }
        $customerId = $ownerContext['customerId'];
        $guestId = null;

        if (!isset($payload['id_cart'])) {
            return response(['error' => 'Cart ID is required'], 400);
        }

        $cart = $this->orderService->getCartFromId($payload['id_cart'], $customerId, $guestId);
        if (is_null($cart)) {
            return response(['error' => 'Cart not found or access denied'], 404);
        }

        try {
            $paymentService = $this->stripeService;
            $orderSession = OrderSession::create([
                'success_url' => $payload['success_url'] ?? $_ENV['STRIPE_SUCCESS_URL'] ?? '',
                'cancel_url' => $payload['cancel_url'] ?? $_ENV['STRIPE_CANCEL_URL'] ?? '',
                'cart_id' => $payload['id_cart'],
            ], $this->orderService);

            // Use variant prices from the ownership-checked PrestaShop cart, never frontend prices.
            foreach ($cart->toArray()['products'] ?? [] as $product) {
                $orderSession->addCartLineItem($product);
            }

            $checkoutUrl = $paymentService->createPaymentSession($orderSession);

            return response(['url' => $checkoutUrl], 200);
        } catch (\Exception $e) {
            return response(['error' => $e->getMessage()], 500);
        }
    }

}
