<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use PS\Webservice\Domain\Entities\OrderEntity;
use PS\Webservice\Domain\Entities\ProductEntity;
use PS\Webservice\Domain\Object\OrderSession;
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
    private const CHECKOUT_ATTEMPT_CACHE_TAG = 'stripe-checkout-attempt';
    private const CHECKOUT_ATTEMPT_CACHE_MINUTES = 1560;
    private const CHECKOUT_ATTEMPT_WINDOW_SECONDS = 3600;
    private const CHECKOUT_ATTEMPT_LIFETIME_SECONDS = 7200;
    private Order $orderService;
    private ?CheckoutSnapshotService $checkoutSnapshots;

    protected PaymentGatewayInterface $stripeService;

    public function __construct(
        Order $orderService,
        PaymentGatewayInterface $stripeService,
        ?PrestashopRepository $prestashopRepository = null,
        ?CheckoutSnapshotService $checkoutSnapshots = null,
    )
    {
        $this->cartService = $orderService;
        $this->prestashopRepository = $prestashopRepository;
        $this->stripeService = $stripeService;
        $this->orderService = $orderService;
        $this->checkoutSnapshots = $checkoutSnapshots;
    }

    public function orderHistory(Request $request, Response $response, array $argv): Response
    {
        $customerId = (int) ($argv['customerId'] ?? 0);
        if ($this->prestashopRepository === null) {
            return response(['error' => 'Unauthorized'], 401);
        }
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
        if ($this->prestashopRepository === null) {
            return response(['error' => 'Unauthorized'], 401);
        }
        $authenticatedCustomerId = $this->resolveAuthenticatedCustomerIdOrDenyFromRepository($request, $this->prestashopRepository);
        if ($authenticatedCustomerId instanceof Response) {
            return $authenticatedCustomerId;
        }

        $cartList = $this->orderService->orderDetails((string) $orderId, (string) $authenticatedCustomerId);

        if (is_null($cartList)) {
            return response([], 404);
        }

        $order = $cartList->toArray();
        if ((int) ($order['id_customer'] ?? 0) !== $authenticatedCustomerId) {
            return response(['message' => 'Order not found'], 404);
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

            $checkoutUrl = $this->createOrReuseCheckout($orderSession, $paymentService, $cart->toArray());

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

    /**
     * Returns the URL of an open Checkout Session for the same server-derived
     * cart. An expired record is retained so its version can never be reused
     * while Stripe still remembers the old idempotency key.
     */
    private function existingCheckoutUrl(OrderSession $orderSession): ?string
    {
        $record = $this->checkoutAttemptRecord($orderSession->checkoutAttemptFingerprint());
        if ($record === null || ($record['state'] ?? null) !== 'open') {
            return null;
        }

        $expiresAt = filter_var($record['expires_at'] ?? null, FILTER_VALIDATE_INT);
        $url = $record['url'] ?? null;
        if ($expiresAt === false || $expiresAt <= time() || !is_string($url) || $url === '') {
            return null;
        }

        return $url;
    }

    /**
     * Serialises the short critical section around the local record. Stripe's
     * idempotency key is still the final guard if the distributed lock expires
     * or a worker crashes after Stripe has accepted the request.
     *
     * @param array<string, mixed> $cart
     */
    private function createOrReuseCheckout(OrderSession $orderSession, PaymentGatewayInterface $paymentService, array $cart): string
    {
        $fingerprint = $orderSession->checkoutAttemptFingerprint();
        $callback = function () use ($orderSession, $paymentService, $cart): string {
            $existingCheckoutUrl = $this->existingCheckoutUrl($orderSession);
            if ($existingCheckoutUrl !== null) {
                return $existingCheckoutUrl;
            }

            [$checkoutFingerprint, $checkoutVersion, $checkoutExpiresAt] = $this->startCheckoutAttempt($orderSession);

            $this->persistCheckoutSnapshot($orderSession, $cart);

            // Save the immutable customer details needed by the webhook only
            // after the attempt key has been attached to the session.
            $this->tags(['order-session'])->setEncryptedToCache(
                $orderSession->metadata['cart_id'],
                $orderSession->toCacheData(),
                24 * 60
            );
            $checkoutUrl = $paymentService->createPaymentSession($orderSession);
            $this->saveCheckoutAttempt(
                $checkoutFingerprint,
                $checkoutVersion,
                $checkoutExpiresAt,
                $checkoutUrl,
            );

            return $checkoutUrl;
        };

        $cache = Cache::getFacadeRoot();
        if (!is_object($cache) || !method_exists($cache, 'lock')) {
            return $callback();
        }

        $lock = $cache->lock('stripe-checkout-attempt:' . $fingerprint, 15);
        if (!is_object($lock) || !method_exists($lock, 'get') || !method_exists($lock, 'release')) {
            return $callback();
        }

        if (!$lock->get()) {
            if (!method_exists($lock, 'block')) {
                throw new \RuntimeException('Checkout is already being prepared.');
            }
            $lock->block(5);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /** @return array{string, int, int} fingerprint, version, expiry */
    private function startCheckoutAttempt(OrderSession $orderSession): array
    {
        $fingerprint = $orderSession->checkoutAttemptFingerprint();
        $record = $this->checkoutAttemptRecord($fingerprint);
        $now = time();
        $version = $record === null
            ? max(1, intdiv($now, self::CHECKOUT_ATTEMPT_WINDOW_SECONDS))
            : max(1, (int) ($record['version'] ?? 0) + 1);

        // Stripe requires repeated idempotent requests to have exactly the
        // same parameters. The expiry is aligned to the key's hourly window,
        // leaving each session open for one to two hours.
        $windowStart = intdiv($now, self::CHECKOUT_ATTEMPT_WINDOW_SECONDS) * self::CHECKOUT_ATTEMPT_WINDOW_SECONDS;
        $expiresAt = $windowStart + self::CHECKOUT_ATTEMPT_LIFETIME_SECONDS;
        $attemptKey = 'dz_checkout_v1_' . $fingerprint . '_' . $version;
        $orderSession->setCheckoutAttempt($attemptKey, $expiresAt);

        return [$fingerprint, $version, $expiresAt];
    }

    private function saveCheckoutAttempt(string $fingerprint, int $version, int $expiresAt, string $url): void
    {
        if ($url === '') {
            throw new \RuntimeException('Stripe returned an empty Checkout Session URL.');
        }

        $this->tags([self::CHECKOUT_ATTEMPT_CACHE_TAG])->setEncryptedToCache(
            $fingerprint,
            [
                'state' => 'open',
                'version' => $version,
                'expires_at' => $expiresAt,
                'url' => $url,
            ],
            self::CHECKOUT_ATTEMPT_CACHE_MINUTES,
        );
    }

    /** @return array{state: string, version: int, expires_at: int, url: string}|null */
    private function checkoutAttemptRecord(string $fingerprint): ?array
    {
        $cached = $this->decryptCachedValue(
            $this->tags([self::CHECKOUT_ATTEMPT_CACHE_TAG])->getFromCache($fingerprint)
        );

        return is_array($cached) ? $cached : null;
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
