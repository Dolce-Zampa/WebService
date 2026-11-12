<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use PS\Webservice\Domain\Entities\OrderEntity;
use PS\Webservice\Domain\Entities\ProductEntity;
use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Http\Controller\CartController;
use PS\Webservice\Service\Payments\PaymentGatewayInterface;
use PS\Webservice\Service\Payments\CheckoutSnapshotService;
use PS\Webservice\Service\Payments\CheckoutPaymentAlreadyCompletedException;
use PS\Webservice\Service\GiftCard\GiftCardApiException;
use PS\Webservice\Service\GiftCard\GiftCardCheckoutRateLimiter;
use PS\Webservice\Service\GiftCard\GiftCardTender;
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
    private ?PrestashopRepository $customerRepository;
    private ?CheckoutSnapshotService $checkoutSnapshots;

    protected PaymentGatewayInterface $stripeService;

    public function __construct(
        Order $orderService,
        PaymentGatewayInterface $stripeService,
        ?PrestashopRepository $customerRepository = null,
        ?CheckoutSnapshotService $checkoutSnapshots = null,
        private readonly ?\Closure $giftCardClientFactory = null,
        private readonly ?\Closure $giftCardSettlementFactory = null,
        private readonly ?GiftCardCheckoutRateLimiter $giftCardRateLimiter = null,
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
            if (filter_var(env('GIFT_CARD_CHECKOUT_ENABLED', false), FILTER_VALIDATE_BOOLEAN) && $this->checkoutSnapshots !== null) {
                $giftSnapshot = $this->checkoutSnapshots->giftCardSnapshotForCart((int) $cartId);
                if ($giftSnapshot !== null) {
                    $owner = $giftSnapshot['payload'];
                    $expectedCustomer = $owner['customer_id'] ?? null;
                    $expectedGuest = $owner['guest_id'] ?? null;
                    if ($expectedCustomer !== null) {
                        if ((int) $customerId !== (int) $expectedCustomer || $this->authenticatedCustomerId($request) !== (int) $expectedCustomer) {
                            return response(['success' => false, 'status' => 'not_found'], 404);
                        }
                    } elseif ($expectedGuest === null || (int) $guestId !== (int) $expectedGuest) {
                        return response(['success' => false, 'status' => 'not_found'], 404);
                    }
                    if (!$giftSnapshot['settled'] || $giftSnapshot['order_id'] === null) {
                        return response(['success' => false, 'status' => 'pending'], 202);
                    }
                    $summary = $this->orderService->giftCardOrderSummary($giftSnapshot['order_id'], (int) $cartId);
                    return response(['success' => true, 'gift_card_status' => 'captured',
                        'order_reference' => $summary['reference'], 'order' => $summary]);
                }
            }
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
                'error' => 'Failed to verify order.'
            ], 500);
        }
    }

    /** Verify available credit without reserving it or creating an order. */
    public function previewGiftCard(Request $request, Response $response, array $argv): Response
    {
        $payload = $request->getParsedBody();
        if (!is_array($payload)) {
            return response(['error' => ['code' => 'card_unavailable']], 422);
        }
        if (!filter_var(env('GIFT_CARD_CHECKOUT_ENABLED', false), FILTER_VALIDATE_BOOLEAN)
            || filter_var(env('APP_DISABLE_CACHE', false), FILTER_VALIDATE_BOOLEAN)
            || $this->giftCardClientFactory === null || $this->giftCardRateLimiter === null) {
            return response(['error' => ['code' => 'temporarily_unavailable']], 503);
        }
        $code = $payload['gift_card'] ?? null;
        $cartId = filter_var($payload['id_cart'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $customerId = $payload['id_customer'] ?? null;
        $guestId = $payload['id_guest'] ?? null;
        if (!is_string($code) || !preg_match('/^GC-[2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4}$/D', $code) || $cartId === false) {
            return response(['error' => ['code' => 'card_unavailable']], 422);
        }
        if (($customerId !== null && ($guestId !== null
                || filter_var($customerId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
                || $this->authenticatedCustomerId($request) !== (int) $customerId))
            || ($customerId === null && filter_var($guestId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) {
            return response(['error' => ['code' => 'unauthorized']], 403);
        }
        try {
            if (!$this->giftCardRateLimiter->allow((string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown'), $cartId)) {
                return response(['error' => ['code' => 'rate_limited']], 429);
            }
            $cart = $this->orderService->getCartFromId($cartId, $customerId, $guestId);
            $products = $cart?->toArray()['products'] ?? [];
            if (!is_array($products) || !array_filter($products, static fn ($line) => is_array($line) && (int) ($line['id_product'] ?? 0) > 0)) {
                return response(['error' => ['code' => 'card_unavailable']], 404);
            }
            $lookup = ['code' => $code];
            if ($customerId !== null) {
                $lookup['customer_id'] = (int) $customerId;
            }
            $card = (($this->giftCardClientFactory)())->post('lookup', $lookup, 'preview:' . bin2hex(random_bytes(16)))['card'] ?? null;
            if (!is_array($card) || !is_string($card['status'] ?? null) || !is_bool($card['expired'] ?? null)
                || !is_int($card['available_cents'] ?? null) || $card['available_cents'] < 0
                || !is_int($card['balance_cents'] ?? null) || $card['balance_cents'] < $card['available_cents']
                || !is_string($card['currency'] ?? null)) {
                throw new \RuntimeException('Invalid gift card lookup response.');
            }
            if ($card['status'] !== 'active') return response(['error' => ['code' => 'card_inactive']], 409);
            if ($card['expired']) return response(['error' => ['code' => 'card_expired']], 409);
            if ($card['currency'] !== 'EUR') return response(['error' => ['code' => 'currency_mismatch']], 409);
            if ($card['available_cents'] === 0) {
                return response(['error' => ['code' => $card['balance_cents'] === 0 ? 'zero_balance' : 'credit_reserved']], 409);
            }
            return response(['available_cents' => $card['available_cents'], 'currency' => 'EUR'])
                ->withHeader('Cache-Control', 'no-store');
        } catch (GiftCardApiException $error) {
            return response(['error' => ['code' => $error->errorCode]], $error->httpStatus);
        } catch (\Throwable $error) {
            return response(['error' => ['code' => 'temporarily_unavailable']], 503);
        }
    }

    /** Expire the exact unpaid Stripe session before returning its reserved credit. */
    public function cancelGiftCardCheckout(Request $request, Response $response, array $argv): Response
    {
        $payload = $request->getParsedBody();
        if (!is_array($payload) || !is_string($payload['checkout_snapshot_id'] ?? null)
            || !preg_match('/^[a-f0-9]{32}$/D', $payload['checkout_snapshot_id'])) {
            return response(['error' => ['code' => 'invalid_input']], 422);
        }
        if ($this->checkoutSnapshots === null || $this->giftCardClientFactory === null) {
            return response(['error' => ['code' => 'temporarily_unavailable']], 503);
        }
        try {
            $snapshot = $this->checkoutSnapshots->giftCardSnapshotById($payload['checkout_snapshot_id']);
            $owner = $snapshot['payload'] ?? null;
            if (!is_array($owner) || (int) ($payload['id_cart'] ?? 0) !== (int) ($owner['cart_id'] ?? 0)) {
                return response(['error' => ['code' => 'card_unavailable']], 404);
            }
            $customerId = $owner['customer_id'] ?? null;
            $guestId = $owner['guest_id'] ?? null;
            if ($customerId !== null) {
                if ((int) ($payload['id_customer'] ?? 0) !== (int) $customerId
                    || $this->authenticatedCustomerId($request) !== (int) $customerId) {
                    return response(['error' => ['code' => 'card_unavailable']], 404);
                }
            } elseif ($guestId === null || (int) ($payload['id_guest'] ?? 0) !== (int) $guestId) {
                return response(['error' => ['code' => 'card_unavailable']], 404);
            }
            $gift = $owner['gift_card'] ?? null;
            if (!is_array($gift) || !is_string($gift['reservation_id'] ?? null)
                || !preg_match('/^[a-f0-9]{32}$/D', $gift['reservation_id'])
                || !is_int($gift['stripe_cents'] ?? null) || $gift['stripe_cents'] <= 0
                || !is_string($snapshot['session_id'] ?? null)) {
                return response(['error' => ['code' => 'temporarily_unavailable']], 503);
            }
            if ($snapshot['verified']) return response(['error' => ['code' => 'checkout_already_paid']], 409);
            $this->stripeService->expireUnpaidSession($snapshot['session_id']);
            // The same stable key is used by the expired-session webhook; retries are safe.
            $this->checkoutSnapshots->markGiftCardReleased($snapshot['id']);
            $released = ($this->giftCardClientFactory)()->release($gift['reservation_id'], 'expired:' . $gift['reservation_id']);
            if (($released['reservation']['id'] ?? null) !== $gift['reservation_id']
                || ($released['reservation']['status'] ?? null) !== 'released') {
                throw new \RuntimeException('Gift card reservation release was not confirmed.');
            }
            return response(['cancelled' => true])->withHeader('Cache-Control', 'no-store');
        } catch (CheckoutPaymentAlreadyCompletedException $error) {
            return response(['error' => ['code' => 'checkout_already_paid']], 409);
        } catch (\Throwable $error) {
            return response(['error' => ['code' => 'temporarily_unavailable']], 503);
        }
    }

    public function createOrder(Request $request, Response $response, array $argv): Response
    {
        $payload = $request->getParsedBody();
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid payload format', 400);
        }

        $giftCode = $payload['gift_card'] ?? null;
        if ($giftCode !== null && (!filter_var(env('GIFT_CARD_CHECKOUT_ENABLED', false), FILTER_VALIDATE_BOOLEAN)
            || filter_var(env('APP_DISABLE_CACHE', false), FILTER_VALIDATE_BOOLEAN)
            || $this->giftCardClientFactory === null || $this->giftCardSettlementFactory === null
            || $this->giftCardRateLimiter === null || $this->checkoutSnapshots === null)) {
            return response(['error' => ['code' => 'temporarily_unavailable']], 503);
        }
        if ($giftCode !== null && (!is_string($giftCode) || !preg_match('/^GC-[2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4}$/D', $giftCode))) {
            return response(['error' => ['code' => 'card_unavailable']], 422);
        }

        // Ownership check: require customer or guest identification — never trust anonymous cart access
        $customerId = isset($payload['id_customer']) ? $payload['id_customer'] : null;
        $guestId = isset($payload['id_guest']) ? $payload['id_guest'] : null;

        if ($customerId === null && $guestId === null) {
            return response(['error' => 'Customer ID or guest ID is required'], 403);
        }
        if ($giftCode !== null) {
            if (($customerId !== null && ($guestId !== null || $this->authenticatedCustomerId($request) !== (int) $customerId))
                || ($customerId === null && filter_var($guestId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) {
                return response(['error' => ['code' => 'unauthorized']], 403);
            }
            if (($payload['payment_method'] ?? 'stripe') !== 'stripe') {
                return response(['error' => ['code' => 'unsupported_payment_method']], 422);
            }
            try {
                if (!$this->giftCardRateLimiter->allow((string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown'), (int) ($payload['id_cart'] ?? 0))) {
                    return response(['error' => ['code' => 'rate_limited']], 429);
                }
            } catch (\Throwable $error) {
                return response(['error' => ['code' => 'temporarily_unavailable']], 503);
            }
            try {
                $existingGift = $this->checkoutSnapshots->giftCardSnapshotForCart((int) ($payload['id_cart'] ?? 0));
                if ($existingGift !== null && $existingGift['verified']) {
                    $existing = $existingGift['payload'];
                    if (($customerId !== null && (int) ($existing['customer_id'] ?? 0) !== (int) $customerId)
                        || ($customerId === null && (int) ($existing['guest_id'] ?? 0) !== (int) $guestId)) {
                        return response(['error' => ['code' => 'card_unavailable']], 404);
                    }
                    $gift = $existing['gift_card'];
                    $summary = ['total_cents' => $existing['totals']['grand_total_cents'],
                        'applied_cents' => $gift['gift_card_cents'], 'remaining_cents' => $gift['stripe_cents'],
                        'platform_cents' => $gift['platform_cents'] ?? 0];
                    if ($gift['stripe_cents'] === 0 && $existingGift['settled'] && $existingGift['order_id'] !== null) {
                        return response(['checkout_status' => 'confirmed', 'gift_card' => $summary,
                            'order' => $this->orderService->giftCardOrderSummary($existingGift['order_id'], (int) $payload['id_cart'])]);
                    }
                    return response(['checkout_status' => 'pending', 'gift_card' => $summary], 202);
                }
            } catch (\Throwable $error) {
                return response(['error' => ['code' => 'temporarily_unavailable']], 503);
            }
        }

        $cart = $this->orderService->getCartFromId($payload['id_cart'], $customerId, $guestId);
        if (is_null($cart)) {
            return response([], 404);
        }
        if ($giftCode === null && filter_var(env('GIFT_CARD_CHECKOUT_ENABLED', false), FILTER_VALIDATE_BOOLEAN)
            && $this->checkoutSnapshots !== null) {
            try {
                if ($this->checkoutSnapshots->giftCardSnapshotForCart((int) $payload['id_cart']) !== null) {
                    return response(['error' => 'A gift card checkout is already in progress for this cart.'], 409);
                }
            } catch (\Throwable $error) {
                return response(['error' => 'Unable to verify checkout status.'], 503);
            }
        }
        $cartData = $cart->toArray();
        $virtual = !empty($cartData['products']) && ($cartData['is_virtual'] ?? false) === true;
        $carrierId = $virtual ? 0 : filter_var($payload['id_carrier'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($carrierId === false) {
            return response(['error' => 'A valid carrier ID is required'], 422);
        }
        $payload['id_carrier'] = $carrierId;

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
            $orderSession = $this->makeOrder($newOrder, $this->orderService, $cart->toArray()['products'] ?? [], $cart->toArray(), $giftCode !== null);
            if (empty($orderSession->metadata['payment_module'])) {
                throw new \InvalidArgumentException('A payment module must be selected or configured.');
            }

            if ($giftCode !== null) {
                $totalCents = (int) round($orderSession->payableTotal() * 100);
                if ($totalCents < 1) {
                    throw new \InvalidArgumentException('The order total must be positive.');
                }
                // A preview is non-binding; never charge a different total without renewed consent.
                $expected = $payload['gift_card_expected'] ?? null;
                if ($expected !== null) {
                    if (!is_array($expected) || !is_int($expected['total_cents'] ?? null)
                        || !is_int($expected['applied_cents'] ?? null) || $expected['total_cents'] < 1
                        || $expected['applied_cents'] < 1 || $expected['applied_cents'] > $expected['total_cents']) {
                        throw new \InvalidArgumentException('Invalid gift card preview.');
                    }
                    if ($expected['total_cents'] !== $totalCents) {
                        return response(['error' => ['code' => 'checkout_changed'],
                            'actual_total_cents' => $totalCents], 409);
                    }
                }
                $giftCards = ($this->giftCardClientFactory)();
                $reservation = $giftCards->reserve($giftCode, (int) $payload['id_cart'],
                    $customerId === null ? null : (int) $customerId, $totalCents,
                    'reserve:' . bin2hex(random_bytes(16)), 604800);
                $tender = GiftCardTender::fromReservation($reservation, (int) $payload['id_cart'],
                    $customerId === null ? null : (int) $customerId, $totalCents, time());
                if ($expected !== null && $expected['applied_cents'] !== $tender->giftCardCents) {
                    // A previous checkout may already have issued a Stripe session using this hold.
                    if (($existingGift['payload']['gift_card']['reservation_id'] ?? null) === $tender->reservationId) {
                        return response(['error' => ['code' => 'cart_reserved']], 409);
                    }
                    $giftCards->release($tender->reservationId, 'release:quote-changed:' . $tender->reservationId);
                    return response(['error' => ['code' => 'checkout_changed'], 'actual_total_cents' => $totalCents,
                        'actual_applied_cents' => $tender->giftCardCents], 409);
                }
                $orderSession->attachGiftCardTender($tender);
                $giftSummary = ['total_cents' => $totalCents, 'applied_cents' => $tender->giftCardCents,
                    'remaining_cents' => $tender->stripeCents, 'platform_cents' => $tender->platformCents, 'expires_at' => $tender->expiresAt];
                if ($tender->stripeCents === 0) {
                    $snapshot = $this->checkoutSnapshots->create($orderSession, $cart->toArray());
                    $this->checkoutSnapshots->markGiftCardPaymentVerified($snapshot['id']);
                    try {
                        $orderId = ($this->giftCardSettlementFactory)()->settle($snapshot['id']);
                    } catch (\Throwable $error) {
                        Log::error('Gift card checkout will be recovered asynchronously.', [
                            'snapshot_id' => $snapshot['id'], 'exception_type' => get_class($error),
                        ]);
                        return response(['checkout_status' => 'pending', 'gift_card' => $giftSummary], 202);
                    }
                    return response(['checkout_status' => 'confirmed', 'gift_card' => $giftSummary,
                        'order' => $this->orderService->giftCardOrderSummary($orderId, (int) $payload['id_cart'])], 201);
                }
                $checkoutUrl = $this->createOrReuseCheckout($orderSession, $paymentService, $cart->toArray());
                $stripeExpires = (int) $orderSession->get('expires_at');
                if ($stripeExpires > time()) {
                    $giftSummary['expires_at'] = gmdate('Y-m-d\TH:i:s\Z', min($stripeExpires, strtotime($tender->expiresAt)));
                }
                $created = $this->checkoutSnapshots->giftCardSnapshotForCart((int) $payload['id_cart']);
                if (($created['payload']['gift_card']['reservation_id'] ?? null) !== $tender->reservationId) {
                    throw new \RuntimeException('Gift card checkout snapshot is unavailable.');
                }
                return response(['checkout_status' => 'payment_required', 'gift_card' => $giftSummary,
                    'checkout_snapshot_id' => $created['id'], 'payment_url' => $checkoutUrl], 201);
            }

            $checkoutUrl = $this->createOrReuseCheckout($orderSession, $paymentService, $cart->toArray());

            return response([
                'order' => $cart->toArray(),
                'payment_url' => $checkoutUrl
            ], 201);
        } catch (GiftCardApiException $e) {
            return response(['error' => ['code' => $e->errorCode]], $e->httpStatus);
        } catch (\InvalidArgumentException $e) {
            Log::critical('Error on create order', ['exception_type' => get_class($e)]);
            return response([
                'order' => $cart->toArray(),
                'error' => $e->getMessage()
            ], 400);
        } catch (\Exception $e) {
            Log::critical('Error on create order', ['exception_type' => get_class($e)]);
            return response([
                'order' => $cart->toArray(),
                'error' => 'Unable to create checkout.'
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
        $store = is_object($cache) && method_exists($cache, 'getStore') ? $cache->getStore() : null;
        if (!is_object($store) || !method_exists($store, 'lock')) {
            if ($orderSession->giftCardTender() !== null) {
                throw new \RuntimeException('Gift card checkout requires a shared lock.');
            }
            return $callback();
        }

        $lock = $store->lock('stripe-checkout-attempt:' . $fingerprint, 60);
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
            return response(['error' => 'Unable to create checkout.'], 500);
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
