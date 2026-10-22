<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Domain\Entities\OrderEntity;
use PS\Webservice\Domain\Entities\ProductEntity;
use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Http\Controller\CartController;
use PS\Webservice\Service\Payments\PaymentGatewayInterface;
use PS\Webservice\Service\Payments\CheckoutSnapshotService;
use PS\Webservice\Service\PS\Order;
use PS\Webservice\Repositories\PrestashopRepository;
use PS\Webservice\Traits\Order as OrderHelper;
use PS\Webservice\Traits\UseCache;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class OrderController extends CartController
{
    use UseCache, OrderHelper;
    private const ORDER_STATE_PAYMENT_ACCEPTED = 2;
    private Order $orderService;
    private ?PrestashopRepository $customerRepository;
    private ?CheckoutSnapshotService $checkoutSnapshots;

    protected PaymentGatewayInterface $stripeService;

    public function __construct(
        Order $orderService,
        PaymentGatewayInterface $stripeService,
        ?PrestashopRepository $customerRepository = null,
        ?CheckoutSnapshotService $checkoutSnapshots = null,
    )
    {
        $this->stripeService = $stripeService;
        $this->orderService = $orderService;
        $this->customerRepository = $customerRepository;
        $this->checkoutSnapshots = $checkoutSnapshots;
    }

    public function orderHistory(Request $request, Response $response, array $argv): Response
    {
        $customerId = (int) ($argv['customerId'] ?? 0);
        $authenticatedCustomerId = $this->authenticatedCustomerId($request);
        if ($authenticatedCustomerId === null) {
            return response(['message' => 'Unauthorized'], 401);
        }
        if ($customerId <= 0 || $authenticatedCustomerId !== $customerId) {
            return response(['message' => 'Order history not found'], 404);
        }

        $orders = $this->orderService->getOrderListFromUserId((string) $customerId);

        if (is_null($orders)) {
            return response([], 404);
        }

        return response($orders);

    }

    public function getOrder(Request $request, Response $response, array $argv): Response
    {
        $authenticatedCustomerId = $this->authenticatedCustomerId($request);
        if ($authenticatedCustomerId === null) {
            return response(['message' => 'Unauthorized'], 401);
        }

        $orderId = $argv['orderId'];
        $cartList = $this->orderService->orderDetails($orderId);

        if (is_null($cartList)) {
            return response([], 404);
        }

        $order = $cartList->toArray();
        if ((int) ($order['id_customer'] ?? 0) !== $authenticatedCustomerId) {
            return response(['message' => 'Order not found'], 404);
        }

        return response($cartList);

    }

    private function authenticatedCustomerId(Request $request): ?int
    {
        $sub = $request->getAttribute('user_id');
        if (!is_string($sub) || $sub === '' || $this->customerRepository === null) {
            return null;
        }

        return $this->customerRepository->findUserIdFromSub($sub);
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

    public function createOrder(Request $request, Response $response, array $argv): Response
    {
        $payload = $request->getParsedBody();
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid payload format', 400);
        }

        // Ownership check: require customer or guest identification — never trust anonymous cart access
        $customerId = isset($payload['id_customer']) ? $payload['id_customer'] : null;
        $guestId = isset($payload['id_guest']) ? $payload['id_guest'] : null;

        if ($customerId === null && $guestId === null) {
            return response(['error' => 'Customer ID or guest ID is required'], 403);
        }

        $carrierId = filter_var($payload['id_carrier'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($carrierId === false) {
            return response(['error' => 'A valid carrier ID is required'], 422);
        }

        $cart = $this->orderService->getCartFromId($payload['id_cart'], $customerId, $guestId);
        if (is_null($cart)) {
            return response([], 404);
        }

        // Create payment session
        try {
            $paymentService = $this->stripeService;
            // Normalize checkout addresses before calling PrestaShop. The module
            // accepts the ISO country too, but deployed versions require the
            // native id_country field when calculating a carrier quote.
            $newOrder = OrderEntity::create($payload, $this->orderService);
            $checkout = $newOrder->toArray();
            $checkout['delivery_address'] = $checkout['customer']['delivery_address'] ?? null;
            $checkout['invoice_address'] = $checkout['customer']['invoice_address'] ?? null;
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
            // Quote the selected carrier with the checkout address before Stripe is
            // created. `cart_rules` accepts an empty code for this purpose; a code,
            // when present, is applied only after the cart has been quoted.
            $cart = $this->orderService->prepareCouponCart(
                $payload['id_cart'],
                $customerId,
                $guestId,
                $carrierId,
                $codes,
                $checkout
            );
            $orderSession = $this->makeOrder($newOrder, $this->orderService, $cart->toArray()['products'] ?? [], $cart->toArray());
            if (empty($orderSession->metadata['payment_module'])) {
                throw new \InvalidArgumentException('A payment module must be selected or configured.');
            }

            $this->persistCheckoutSnapshot($orderSession, $cart->toArray());

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

    /** @param array<string, mixed> $cart */
    private function persistCheckoutSnapshot(OrderSession $orderSession, array $cart): void
    {
        if ($this->checkoutSnapshots === null) {
            return;
        }

        $snapshot = $this->checkoutSnapshots->create($orderSession, $cart);
        $orderSession->setCheckoutSnapshotId($snapshot['id']);
    }

}
