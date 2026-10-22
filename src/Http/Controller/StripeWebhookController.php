<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PS\Webservice\Domain\Financial\FinancialMovement;
use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Domain\Entities\OrderEntity;
use PS\Webservice\Domain\Entities\ProductEntity;
use PS\Webservice\Domain\Models\PS\Products\Product;
use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Service\MailerInterface;
use PS\Webservice\Service\Financial\FinancialLedgerService;
use PS\Webservice\Service\Financial\SellerSaleNotificationService;
use PS\Webservice\Service\MailjetService;
use PS\Webservice\Service\Payments\PaymentGatewayInterface;
use PS\Webservice\Service\Payments\CheckoutSnapshotService;
use PS\Webservice\Service\Payments\StripeConnectService;
use PS\Webservice\Service\PS\Order;
use PS\Webservice\Service\Promotions\PromotionService;
use PS\Webservice\Traits\Order as OrderTrait;
use PS\Webservice\Traits\UseCache;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class StripeWebhookController extends OrderController
{
    use UseCache, OrderTrait;
    private const DEFAULT_WEBHOOK_TOLERANCE_SECONDS = 300;
    private const MAX_WEBHOOK_TOLERANCE_SECONDS = 300;
    private const CHECKOUT_CONFIRMATION_LOCK_SECONDS = 30;
    private const CHECKOUT_CONFIRMATION_MARKER_MINUTES = 43200;
    private Order $orderService;
    private MailjetService $mailjetService;
    protected PaymentGatewayInterface $stripeService;
    private ?PromotionService $promotionService = null;
    private ?FinancialLedgerService $financialLedger;
    private ?SellerSaleNotificationService $sellerSaleNotifications;
    private ?StripeConnectService $stripeConnect;
    private ?CheckoutSnapshotService $checkoutSnapshots;

    private MailerInterface $mailer;

    public function __construct(
        Order $orderService,
        MailjetService $mailjetService,
        PaymentGatewayInterface $stripeService,
        MailerInterface $mailer,
        mixed $legacyDependency = null,
        ?PromotionService $promotionService = null,
        ?FinancialLedgerService $financialLedger = null,
        ?SellerSaleNotificationService $sellerSaleNotifications = null,
        ?StripeConnectService $stripeConnect = null,
        ?CheckoutSnapshotService $checkoutSnapshots = null,
    )
    {
        $this->orderService = $orderService;
        $this->mailjetService = $mailjetService;
        $this->stripeService = $stripeService;
        $this->mailer = $mailer;
        if ($legacyDependency instanceof PromotionService && $promotionService === null) {
            $this->promotionService = $legacyDependency;
        } else {
            $this->promotionService = $promotionService;
        }
        $this->financialLedger = $financialLedger;
        $this->sellerSaleNotifications = $sellerSaleNotifications;
        $this->stripeConnect = $stripeConnect;
        $this->checkoutSnapshots = $checkoutSnapshots;
    }
    //https://hkdk.events/q2u3lxvs2zpfu7 
    public function handleWebhook(Request $request, Response $response, array $argv): Response
    {
    //     $this->orderSession = OrderSession::create(
    //         [
    //             'id_carrier' => 1,
    //             'cart_id' => 553,
    //             'customer' => CustomerEntity::create([], $this->orderService)
    //         ], $this->orderService
    //     );

    //     $cart = $this->orderService->getCartFromId(553, null, 297);

    //      foreach ($cart->toArray()['products'] ?? [] as $product) {
    //         $this->addProduct($product);
    //     }

    //     $test = $this->getProducts();

        $payload = (string) $request->getBody();
        $sigHeader = $request->getHeaderLine('Stripe-Signature');
        $endpointSecret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? null;

        if (empty($endpointSecret)) {
            Log::error('Stripe webhook: STRIPE_WEBHOOK_SECRET is not configured');
            return response(['error' => 'Webhook secret not configured'], 500);
        }

        try {
            $event = $this->constructStripeEvent($payload, $sigHeader, $endpointSecret);
        } catch (\UnexpectedValueException $e) {
            Log::warning('Stripe webhook: invalid payload received');
            return response(['error' => 'Invalid payload'], 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::warning('Stripe webhook: invalid signature');
            return response(['error' => 'Invalid signature'], 400);
        }

        if (in_array($event->type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            try {
                if (!$this->isPaidCheckoutSession($event->data->object)) {
                    // `checkout.session.completed` is also delivered for
                    // delayed payment methods. In that case Stripe has
                    // accepted the Checkout flow, but has not collected the
                    // money yet. The later async success event is the only
                    // event that may create the order or seller allocations.
                    $this->recordFinancialWebhook($event);
                    Log::info('Stripe webhook: checkout payment is not yet paid; confirmation deferred.', [
                        'event_type' => (string) $event->type,
                        'event_id' => (string) ($event->id ?? ''),
                    ]);

                    return response(['received' => true], 200);
                }

                if ($this->promotionService !== null && $this->promotionService->isPromotionCheckoutSession($event->data->object)) {
                    $this->promotionService->activatePromotionFromStripeSession($event->data->object);
                    return response(['received' => true], 200);
                }
                $cartId = $this->handleCheckoutSessionCompleted($event->data->object);
                if ($cartId !== null && $this->sellerSaleNotifications !== null) {
                    // This happens only after Stripe's signature has been
                    // checked and PrestaShop has accepted the confirmation.
                    // The notification service is best-effort and journals
                    // its own idempotent delivery state.
                    $this->sellerSaleNotifications->notifyFinalizedSalesForCart(
                        $cartId,
                        $this->stripeEventTimestamp($event->created ?? null),
                    );
                }
            } catch (\Throwable $e) {
                Log::critical('Stripe webhook: failed to confirm a paid checkout session.', [
                    'event_type' => (string) $event->type,
                    'event_id' => (string) ($event->id ?? ''),
                    'exception' => $e,
                ]);
                return response(['error' => 'Failed to process event'], 500);
            }

            try {
                $this->recordFinancialWebhook($event);
            } catch (\Throwable $e) {
                // The order was already confirmed by PrestaShop. Do not make
                // Stripe retry a successful checkout because reconciliation
                // data cannot be recorded yet (for example, during a pending
                // ledger migration); it can be backfilled separately.
                Log::critical('Stripe webhook: financial ledger recording failed after checkout confirmation.', [
                    'event_type' => (string) $event->type,
                    'event_id' => (string) ($event->id ?? ''),
                    'exception' => $e,
                ]);
            }
            try {
                $this->createStripeConnectTransfers($event->data->object, $event);
            } catch (\Throwable $e) {
                Log::critical('Stripe webhook: failed to create Stripe Connect transfers after checkout confirmation.', [
                    'event_type' => (string) $event->type,
                    'event_id' => (string) ($event->id ?? ''),
                    'exception' => $e,
                ]);
                return response(['error' => 'Failed to allocate seller transfers'], 500);
            }

            return response(['received' => true], 200);
        }

        if ($event->type === 'account.updated') {
            try {
                if ($this->stripeConnect !== null && $this->stripeConnect->enabled()) {
                    $account = $event->data->object;
                    if (!$account instanceof \Stripe\StripeObject) {
                        throw new \RuntimeException('Invalid Stripe Connect account update.');
                    }
                    $this->stripeConnect->handleAccountUpdated(
                        $account,
                        $this->stripeEventTimestamp($event->created ?? null),
                    );
                }
            } catch (\Throwable $e) {
                // Returning 500 here asks Stripe to redeliver the verified
                // account event. A payment is never rolled back: allocations
                // have already been stored as pending by checkout handling.
                Log::critical('Stripe webhook: failed to reconcile Stripe Connect account update.', [
                    'event_id' => (string) ($event->id ?? ''),
                    'exception' => $e,
                ]);
                return response(['error' => 'Failed to reconcile seller transfers'], 500);
            }

            return response(['received' => true], 200);
        }

        if( $event->type === 'checkout.session.expired') {
            try {
                $this->handleCheckoutSessionExpired($event->data->object);
                $this->recordFinancialWebhook($event);
            } catch (\Exception $e) {
                Log::critical('Stripe webhook: failed to process checkout.session.expired: ' . $e->getMessage());
                return response(['error' => 'Failed to process event'], 500);
            }
        }

        // if session expired or payment failed, we can handle other event types here (e.g. "checkout.session.expired", "payment_intent.payment_failed") to update the order status in PrestaShop accordingly.
        if ($event->type === 'checkout.session.expired' || $event->type === 'payment_intent.payment_failed') {
            Log::info('Stripe webhook: checkout session expired or failed');
        }

        if (!in_array($event->type, ['checkout.session.completed', 'checkout.session.expired'], true)) {
            try {
                $this->recordFinancialWebhook($event);
            } catch (\Exception $e) {
                // Do not include provider payloads, email addresses, or payment data in logs.
                Log::critical('Stripe webhook: failed to record financial event ' . $event->type . ': ' . $e->getMessage());
                return response(['error' => 'Failed to process financial event'], 500);
            }
        }

        return response(['received' => true], 200);
    }

    /**
     * Constructs and verifies a Stripe event from the raw request payload and signature.
     * Extracted to allow overriding in tests.
     *
     * @throws \UnexpectedValueException if the payload is invalid
     * @throws \Stripe\Exception\SignatureVerificationException if the signature is invalid
     */
    protected function constructStripeEvent(string $payload, string $sigHeader, string $secret): \Stripe\Event
    {
        // Stripe validates the signature timestamp as part of verification.
        // Keep a short, bounded tolerance so an intercepted signed request
        // cannot be replayed long after it was issued; exact event retries are
        // additionally harmless because the ledger uses the Stripe event id
        // as its idempotency key.
        return \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret, $this->webhookToleranceSeconds());
    }

    private function webhookToleranceSeconds(): int
    {
        $configured = $_ENV['STRIPE_WEBHOOK_TOLERANCE_SECONDS'] ?? self::DEFAULT_WEBHOOK_TOLERANCE_SECONDS;
        if (filter_var($configured, FILTER_VALIDATE_INT) === false) {
            return self::DEFAULT_WEBHOOK_TOLERANCE_SECONDS;
        }

        $tolerance = (int) $configured;
        if ($tolerance < 1 || $tolerance > self::MAX_WEBHOOK_TOLERANCE_SECONDS) {
            return self::DEFAULT_WEBHOOK_TOLERANCE_SECONDS;
        }

        return $tolerance;
    }

    /**
     * Copies only reconciliation fields from a verified Stripe webhook into
     * the financial journal. Checkout completion is `available`, not `paid`:
     * `paid` remains reserved for a later settlement/payout lifecycle.
     *
     * The webhook id is hashed into the idempotency key because provider ids
     * have no application-controlled maximum length. The original id remains
     * in the source-event column for reconciliation.
     */
    protected function recordFinancialWebhook(\Stripe\Event $event): void
    {
        if (str_starts_with((string) $event->type, 'refund.')) {
            if ($this->financialLedger !== null) {
                $this->recordStripeRefundWebhook($event);
            }
            $this->reconcileStripeConnectRefund($event);
            return;
        }

        if (str_starts_with((string) $event->type, 'charge.dispute.')) {
            if ($this->financialLedger !== null) {
                $this->recordStripeDisputeWebhook($event);
            }
            $this->registerStripeConnectDispute($event);
            return;
        }

        if ($this->financialLedger === null) {
            return;
        }

        $status = $this->financialStatusForEvent($event->data->object, (string) $event->type);
        if ($status === null) {
            return;
        }

        $object = $event->data->object;
        if (!$object instanceof \Stripe\StripeObject) {
            throw new \RuntimeException('Invalid Stripe financial event object.');
        }

        $eventId = $this->stripeIdentifier($event->id ?? null, 'event');
        $sourceEventType = 'stripe.' . (string) $event->type;
        $occurredAt = $this->stripeEventTimestamp($event->created ?? null);
        $sessionId = $this->stripeSessionId($object, (string) $event->type);
        $transactionId = $this->stripeTransactionId($object, (string) $event->type, $sessionId);
        $metadata = $this->financialMetadata($object, $sessionId, $transactionId);
        $idempotencyKey = 'stripe:webhook:' . hash('sha256', $eventId);

        $existing = $transactionId === null
            ? null
            : $this->financialTransactionByProviderTransaction($transactionId);
        // A Checkout Session can be created before Stripe allocates its
        // PaymentIntent. Session correlation prevents that normal sequence
        // from becoming two financial movements.
        $existing ??= $sessionId === null ? null : $this->financialTransactionBySession($sessionId);

        if ($existing !== null) {
            [$orderId, $orderReference] = $this->financialOrderReference($object);
            if ($orderId !== null) {
                $metadata['order_id'] = $orderId;
            }
            if ($orderReference !== null) {
                $metadata['order_reference'] = $orderReference;
            }
            $this->financialLedger->recordProviderStatus(
                (int) $existing->id,
                $status,
                $idempotencyKey,
                $occurredAt,
                $sourceEventType,
                $eventId,
                $metadata,
            );
            $this->recordStripeProviderFee($event, $object, $transactionId);
            return;
        }

        [$orderId, $orderReference] = $this->financialOrderReference($object);
        $this->financialLedger->record(FinancialMovement::fromArray([
            'type' => 'payment',
            'status' => $status,
            'amount' => $this->stripeGrossAmount($object),
            'currency' => $this->stripeCurrency($object),
            'occurred_at' => $occurredAt,
            'order_id' => $orderId,
            'customer_id' => $this->financialCustomerId($object),
            'order_reference' => $orderReference,
            'provider' => 'stripe',
            'provider_account_id' => $this->stripeOptionalIdentifier($event->account ?? null),
            'provider_transaction_id' => $transactionId,
            'provider_event_id' => $eventId,
            'source_event_type' => $sourceEventType,
            'source_event_id' => $eventId,
            'metadata' => $metadata,
        ]), $idempotencyKey);
        $this->recordStripeProviderFee($event, $object, $transactionId);
    }

    /**
     * A Stripe Refund is an economic reversal, not a state mutation of the
     * payment. Each `re_...` object therefore creates one negative `refund`
     * movement linked to the original `payment`; later webhooks for that same
     * Refund append its lifecycle event instead.
     *
     * Stripe does not provide a commission formula or a refund shipping
     * allocation on this object. Those facts are intentionally not inferred
     * here: their own movements require a provider-supplied amount and their
     * corresponding original commission/payout movement.
     */
    private function recordStripeRefundWebhook(\Stripe\Event $event): void
    {
        $object = $event->data->object;
        if (!$object instanceof \Stripe\StripeObject) {
            throw new \RuntimeException('Invalid Stripe refund event object.');
        }

        $eventId = $this->stripeIdentifier($event->id ?? null, 'event');
        $refundId = $this->stripeIdentifier($object->id ?? null, 'refund');
        $paymentIntentId = $this->stripeRefundPaymentIntentId($object);
        if ($paymentIntentId === null) {
            throw new \RuntimeException('Stripe refund has no payment intent for ledger correlation.');
        }

        $sourceEventType = 'stripe.' . (string) $event->type;
        $occurredAt = $this->stripeEventTimestamp($event->created ?? null);
        $status = $this->stripeRefundStatus($object);
        $idempotencyKey = 'stripe:webhook:' . hash('sha256', $eventId);
        $existingRefund = $this->financialTransactionByProviderTransaction($refundId);

        if ($existingRefund !== null) {
            $this->financialLedger->recordProviderStatus(
                (int) $existingRefund->id,
                $status,
                $idempotencyKey,
                $occurredAt,
                $sourceEventType,
                $eventId,
                $this->stripeRefundMetadata($object, $refundId, $paymentIntentId),
            );
            return;
        }

        $originalPayment = $this->financialLedger->findPaymentByProviderTransaction('stripe', $paymentIntentId);
        if ($originalPayment === null) {
            // Returning an error makes Stripe retry this webhook if events are
            // delivered out of order. Recording an orphan would break the
            // immutable audit relationship required for a refund.
            throw new \RuntimeException('Stripe refund original payment is not recorded.');
        }

        $currency = $this->stripeCurrency($object);
        if ($currency !== (string) $originalPayment->currency) {
            throw new \RuntimeException('Stripe refund currency differs from the original payment.');
        }

        $refundAmount = $this->stripeGrossAmount($object);
        if (preg_match('/^0(?:\\.0+)?$/', $refundAmount) === 1) {
            throw new \RuntimeException('Stripe refund has no positive amount.');
        }

        $this->financialLedger->recordReversal((int) $originalPayment->id, FinancialMovement::fromArray([
            'type' => 'refund',
            'status' => $status,
            'amount' => '-' . $refundAmount,
            'currency' => $currency,
            'occurred_at' => $occurredAt,
            'order_id' => $this->positiveInteger($originalPayment->order_id),
            'customer_id' => $this->positiveInteger($originalPayment->customer_id),
            'artisan_id' => $this->positiveInteger($originalPayment->artisan_id),
            'order_item_id' => $this->positiveInteger($originalPayment->order_item_id),
            'order_reference' => $this->stripeOptionalIdentifier($originalPayment->order_reference),
            'artisan_reference' => $this->stripeOptionalIdentifier($originalPayment->artisan_reference),
            'order_item_reference' => $this->stripeOptionalIdentifier($originalPayment->order_item_reference),
            'provider' => 'stripe',
            'provider_account_id' => $this->stripeOptionalIdentifier($event->account ?? null) ?? $this->stripeOptionalIdentifier($originalPayment->provider_account_id),
            'provider_transaction_id' => $refundId,
            'provider_event_id' => $eventId,
            'source_event_type' => $sourceEventType,
            'source_event_id' => $eventId,
            'metadata' => $this->stripeRefundMetadata($object, $refundId, $paymentIntentId),
        ]), $idempotencyKey);
    }

    private function reconcileStripeConnectRefund(\Stripe\Event $event): void
    {
        if ($this->stripeConnect === null || !$this->stripeConnect->enabled()) {
            return;
        }

        $object = $event->data->object;
        if (!$object instanceof \Stripe\StripeObject) {
            throw new \RuntimeException('Invalid Stripe refund event object.');
        }
        $this->stripeConnect->reconcileRefund(
            $this->stripeIdentifier($this->stripeRefundPaymentIntentId($object), 'refund payment intent'),
            $this->stripeIdentifier($object->id ?? null, 'refund'),
            $this->stripeMinorAmountValue($object),
            $this->stripeCurrency($object),
            $this->stripeRefundStatus($object),
            $this->stripeEventTimestamp($event->created ?? null),
        );
    }

    /**
     * A dispute belongs to the platform charge. Record its lifecycle against
     * that customer payment and retain the linked seller recovery positions;
     * a Transfer reversal remains a separate, explicit business decision.
     */
    private function recordStripeDisputeWebhook(\Stripe\Event $event): void
    {
        $object = $event->data->object;
        if (!$object instanceof \Stripe\StripeObject) {
            throw new \RuntimeException('Invalid Stripe dispute event object.');
        }

        $eventId = $this->stripeIdentifier($event->id ?? null, 'event');
        $disputeId = $this->stripeIdentifier($object->id ?? null, 'dispute');
        $paymentIntentId = $this->stripeDisputePaymentIntentId($object);
        if ($paymentIntentId === null) {
            throw new \RuntimeException('Stripe dispute has no payment intent for ledger correlation.');
        }
        $sourceEventType = 'stripe.' . (string) $event->type;
        $occurredAt = $this->stripeEventTimestamp($event->created ?? null);
        $status = $this->stripeDisputeStatus($object);
        $idempotencyKey = 'stripe:webhook:' . hash('sha256', $eventId);
        $metadata = $this->stripeDisputeMetadata($object, $disputeId, $paymentIntentId);
        $existing = $this->financialTransactionByProviderTransaction($disputeId);

        if ($existing !== null) {
            $this->financialLedger?->recordProviderStatus(
                (int) $existing->id,
                $status,
                $idempotencyKey,
                $occurredAt,
                $sourceEventType,
                $eventId,
                $metadata,
            );
            return;
        }

        $payment = $this->financialLedger?->findPaymentByProviderTransaction('stripe', $paymentIntentId);
        if ($payment === null) {
            throw new \RuntimeException('Stripe dispute original payment is not recorded.');
        }
        $currency = $this->stripeCurrency($object);
        if ($currency !== (string) $payment->currency) {
            throw new \RuntimeException('Stripe dispute currency differs from the original payment.');
        }

        $this->financialLedger?->recordReversal((int) $payment->id, FinancialMovement::fromArray([
            'type' => 'chargeback',
            'status' => $status,
            'amount' => '-' . $this->stripeGrossAmount($object),
            'currency' => $currency,
            'occurred_at' => $occurredAt,
            'order_id' => $this->positiveInteger($payment->order_id),
            'customer_id' => $this->positiveInteger($payment->customer_id),
            'order_reference' => $this->stripeOptionalIdentifier($payment->order_reference),
            'provider' => 'stripe',
            'provider_account_id' => $this->stripeOptionalIdentifier($event->account ?? null) ?? $this->stripeOptionalIdentifier($payment->provider_account_id),
            'provider_transaction_id' => $disputeId,
            'provider_event_id' => $eventId,
            'source_event_type' => $sourceEventType,
            'source_event_id' => $eventId,
            'metadata' => $metadata,
        ]), $idempotencyKey);
    }

    private function registerStripeConnectDispute(\Stripe\Event $event): void
    {
        if ($this->stripeConnect === null || !$this->stripeConnect->enabled()) {
            return;
        }
        $object = $event->data->object;
        if (!$object instanceof \Stripe\StripeObject) {
            throw new \RuntimeException('Invalid Stripe dispute event object.');
        }
        $chargeId = $this->stripeOptionalIdentifier($object->toArray()['charge'] ?? null);
        if ($chargeId === null) {
            throw new \RuntimeException('Stripe dispute has no charge for seller recovery correlation.');
        }
        $this->stripeConnect->registerDispute(
            $chargeId,
            $this->stripeIdentifier($object->id ?? null, 'dispute'),
            $this->stripeMinorAmountValue($object),
            $this->stripeCurrency($object),
        );
    }

    private function financialStatusForEvent(\Stripe\StripeObject $object, string $eventType): ?string
    {
        return match ($eventType) {
            'checkout.session.created',
            'checkout.session.async_payment_pending',
            'payment_intent.created',
            'payment_intent.processing',
            'payment_intent.requires_action',
            'payment_intent.requires_payment_method' => 'pending',
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded' => $this->isPaidCheckoutSession($object) ? 'available' : 'pending',
            'payment_intent.succeeded' => 'available',
            'checkout.session.async_payment_failed',
            'payment_intent.payment_failed' => 'failed',
            'checkout.session.expired' => 'cancelled',
            default => null,
        };
    }

    private function isPaidCheckoutSession(\Stripe\StripeObject $session): bool
    {
        return ($session->toArray()['payment_status'] ?? null) === 'paid';
    }

    private function financialTransactionByProviderTransaction(string $providerTransactionId): ?object
    {
        return $this->financialLedger?->findByProviderTransaction('stripe', $providerTransactionId);
    }

    private function stripeRefundPaymentIntentId(\Stripe\StripeObject $object): ?string
    {
        $data = $object->toArray();
        $paymentIntent = $data['payment_intent'] ?? null;
        if (is_array($paymentIntent)) {
            $paymentIntent = $paymentIntent['id'] ?? null;
        } elseif ($paymentIntent instanceof \Stripe\StripeObject) {
            $paymentIntent = $paymentIntent->id ?? null;
        }

        return $this->stripeOptionalIdentifier($paymentIntent);
    }

    private function stripeRefundStatus(\Stripe\StripeObject $object): string
    {
        return match ($this->stripeOptionalIdentifier($object->toArray()['status'] ?? null)) {
            'pending', 'requires_action' => 'pending',
            'succeeded' => 'available',
            'failed' => 'failed',
            'canceled' => 'cancelled',
            default => throw new \RuntimeException('Stripe refund has no supported status.'),
        };
    }

    private function stripeDisputePaymentIntentId(\Stripe\StripeObject $object): ?string
    {
        $data = $object->toArray();
        $paymentIntent = $data['payment_intent'] ?? null;
        if (is_array($paymentIntent)) {
            $paymentIntent = $paymentIntent['id'] ?? null;
        } elseif ($paymentIntent instanceof \Stripe\StripeObject) {
            $paymentIntent = $paymentIntent->id ?? null;
        }

        return $this->stripeOptionalIdentifier($paymentIntent);
    }

    private function stripeDisputeStatus(\Stripe\StripeObject $object): string
    {
        return match ($this->stripeOptionalIdentifier($object->toArray()['status'] ?? null)) {
            'needs_response', 'warning_needs_response', 'under_review', 'warning_under_review' => 'pending',
            'lost' => 'available',
            'won' => 'reversed',
            'warning_closed' => 'cancelled',
            default => throw new \RuntimeException('Stripe dispute has no supported status.'),
        };
    }

    /** @return array<string, string> */
    private function stripeRefundMetadata(\Stripe\StripeObject $object, string $refundId, string $paymentIntentId): array
    {
        $metadata = [
            'stripe_refund_id' => $refundId,
            'stripe_payment_intent_id' => $paymentIntentId,
        ];
        $chargeId = $this->stripeOptionalIdentifier($object->toArray()['charge'] ?? null);
        if ($chargeId !== null) {
            $metadata['stripe_charge_id'] = $chargeId;
        }
        $status = $this->stripeOptionalIdentifier($object->toArray()['status'] ?? null);
        if ($status !== null) {
            $metadata['stripe_refund_status'] = $status;
        }

        return $metadata;
    }

    /** @return array<string, string> */
    private function stripeDisputeMetadata(\Stripe\StripeObject $object, string $disputeId, string $paymentIntentId): array
    {
        $metadata = [
            'stripe_dispute_id' => $disputeId,
            'stripe_payment_intent_id' => $paymentIntentId,
        ];
        $data = $object->toArray();
        $chargeId = $this->stripeOptionalIdentifier($data['charge'] ?? null);
        if ($chargeId !== null) {
            $metadata['stripe_charge_id'] = $chargeId;
        }
        $status = $this->stripeOptionalIdentifier($data['status'] ?? null);
        if ($status !== null) {
            $metadata['stripe_dispute_status'] = $status;
        }

        return $metadata;
    }

    private function financialTransactionBySession(string $providerSessionId): ?object
    {
        return $this->financialLedger?->findByProviderSession('stripe', $providerSessionId);
    }

    /** Customer IDs are stored in a dedicated ledger column, never in metadata. */
    private function financialCustomerId(\Stripe\StripeObject $object): ?int
    {
        return $this->positiveInteger($this->stripeMetadata($object->toArray())['id_customer'] ?? null);
    }

    /**
     * Stripe sometimes expands balance_transaction on a Charge/PaymentIntent
     * event. When that exact fee is present, journal it; never estimate a fee
     * from the gross payment.
     */
    private function recordStripeProviderFee(\Stripe\Event $event, \Stripe\StripeObject $object, ?string $transactionId): void
    {
        if ($this->financialLedger === null || $transactionId === null) return;
        $data = $object->toArray();
        $balance = $data['balance_transaction'] ?? null;
        if ($balance instanceof \Stripe\StripeObject) $balance = $balance->toArray();
        if (!is_array($balance) || !isset($balance['fee']) || (!is_int($balance['fee']) && !(is_string($balance['fee']) && ctype_digit($balance['fee'])))) return;
        $feeMinor = (int) $balance['fee'];
        if ($feeMinor < 1) return;
        $payment = $this->financialTransactionByProviderTransaction($transactionId);
        if ($payment === null) return;
        $balanceId = $this->stripeOptionalIdentifier($balance['id'] ?? null) ?? $this->stripeIdentifier($event->id ?? null, 'event');
        $eventId = $this->stripeIdentifier($event->id ?? null, 'event');
        $this->financialLedger->record(FinancialMovement::fromArray([
            'type' => 'provider_fee', 'status' => 'available',
            'amount' => '-' . $this->stripeMinorAmount($feeMinor, (string) $payment->currency),
            'currency' => (string) $payment->currency, 'occurred_at' => $this->stripeEventTimestamp($event->created ?? null),
            'order_id' => $this->positiveInteger($payment->order_id), 'customer_id' => $this->positiveInteger($payment->customer_id),
            'order_reference' => $this->stripeOptionalIdentifier($payment->order_reference), 'provider' => 'stripe',
            'provider_transaction_id' => $balanceId, 'provider_event_id' => $eventId . ':fee',
            'source_event_type' => 'stripe.balance_transaction.fee', 'source_event_id' => $balanceId,
            'metadata' => ['stripe_balance_transaction_id' => $balanceId],
        ]), 'stripe:fee:' . hash('sha256', $balanceId));
    }

    private function stripeSessionId(\Stripe\StripeObject $object, string $eventType): ?string
    {
        $data = $object->toArray();
        if (str_starts_with($eventType, 'checkout.session.')) {
            return $this->stripeOptionalIdentifier($data['id'] ?? null);
        }

        $metadata = $this->stripeMetadata($data);
        return $this->stripeOptionalIdentifier($metadata['checkout_session_id'] ?? null);
    }

    private function stripeTransactionId(\Stripe\StripeObject $object, string $eventType, ?string $sessionId): ?string
    {
        $data = $object->toArray();
        if (str_starts_with($eventType, 'payment_intent.')) {
            return $this->stripeOptionalIdentifier($data['id'] ?? null);
        }

        $paymentIntent = $data['payment_intent'] ?? null;
        if (is_array($paymentIntent)) {
            $paymentIntent = $paymentIntent['id'] ?? null;
        } elseif ($paymentIntent instanceof \Stripe\StripeObject) {
            $paymentIntent = $paymentIntent->id ?? null;
        }

        return $this->stripeOptionalIdentifier($paymentIntent);
    }

    /** @return array<string, string|int> */
    private function financialMetadata(\Stripe\StripeObject $object, ?string $sessionId, ?string $transactionId): array
    {
        $data = $object->toArray();
        $metadata = $this->stripeMetadata($data);
        $financialMetadata = [];

        if ($sessionId !== null) {
            $financialMetadata['stripe_session_id'] = $sessionId;
        }
        if ($transactionId !== null) {
            $financialMetadata['stripe_payment_intent_id'] = $transactionId;
        }
        $cartId = $this->positiveInteger($metadata['cart_id'] ?? null);
        if ($cartId !== null) {
            $financialMetadata['cart_id'] = $cartId;
        }
        $paymentStatus = $this->stripeOptionalIdentifier($data['payment_status'] ?? $data['status'] ?? null);
        if ($paymentStatus !== null) {
            $financialMetadata['stripe_payment_status'] = $paymentStatus;
        }

        return $financialMetadata;
    }

    /** @return array{0: ?int, 1: ?string} */
    private function financialOrderReference(\Stripe\StripeObject $object): array
    {
        $metadata = $this->stripeMetadata($object->toArray());
        $cartId = $this->positiveInteger($metadata['cart_id'] ?? null);
        if ($cartId === null) {
            return [null, null];
        }

        try {
            $order = $this->orderService->getOrderByCartId(
                $cartId,
                $this->positiveInteger($metadata['id_customer'] ?? null),
                $this->positiveInteger($metadata['id_guest'] ?? null),
            );
        } catch (\Throwable) {
            // Stripe is the financial source of truth. An unavailable order
            // lookup must not discard its verified payment event.
            return [null, null];
        }
        if ($order === null) {
            return [null, null];
        }

        $orderData = $order->toArray();
        return [
            $this->positiveInteger($orderData['id'] ?? null),
            $this->stripeOptionalIdentifier($orderData['reference'] ?? null),
        ];
    }

    private function stripeGrossAmount(\Stripe\StripeObject $object): string
    {
        $data = $object->toArray();
        $amount = $data['amount_total'] ?? $data['amount'] ?? null;
        if (!is_int($amount) && !(is_string($amount) && ctype_digit($amount))) {
            throw new \RuntimeException('Stripe financial event has no valid amount.');
        }
        $minorUnits = (int) $amount;
        if ($minorUnits < 0) {
            throw new \RuntimeException('Stripe financial event has a negative gross amount.');
        }

        $currency = $this->stripeCurrency($object);
        if (in_array($currency, ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'], true)) {
            return (string) $minorUnits;
        }

        return intdiv($minorUnits, 100) . '.' . str_pad((string) ($minorUnits % 100), 2, '0', STR_PAD_LEFT);
    }

    private function stripeMinorAmountValue(\Stripe\StripeObject $object): int
    {
        $amount = $object->toArray()['amount'] ?? null;
        if (!is_int($amount) && !(is_string($amount) && ctype_digit($amount))) {
            throw new \RuntimeException('Stripe financial event has no valid amount.');
        }
        if ((int) $amount < 1) {
            throw new \RuntimeException('Stripe financial event has no positive amount.');
        }

        return (int) $amount;
    }

    private function stripeMinorAmount(int $minorUnits, string $currency): string
    {
        if (in_array(strtoupper($currency), ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'], true)) return (string) $minorUnits;
        return intdiv($minorUnits, 100) . '.' . str_pad((string) ($minorUnits % 100), 2, '0', STR_PAD_LEFT);
    }

    private function stripeCurrency(\Stripe\StripeObject $object): string
    {
        $currency = strtoupper((string) ($object->toArray()['currency'] ?? ''));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \RuntimeException('Stripe financial event has no valid currency.');
        }

        return $currency;
    }

    private function stripeEventTimestamp(mixed $timestamp): DateTimeImmutable
    {
        if ((!is_int($timestamp) && !(is_string($timestamp) && ctype_digit($timestamp))) || (int) $timestamp < 0) {
            throw new \RuntimeException('Stripe financial event has no valid timestamp.');
        }

        return (new DateTimeImmutable('@' . (int) $timestamp))->setTimezone(new \DateTimeZone('UTC'));
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function stripeMetadata(array $data): array
    {
        $metadata = $data['metadata'] ?? [];
        if ($metadata instanceof \Stripe\StripeObject) {
            $metadata = $metadata->toArray();
        }

        return is_array($metadata) ? $metadata : [];
    }

    private function stripeIdentifier(mixed $value, string $kind): string
    {
        $identifier = $this->stripeOptionalIdentifier($value);
        if ($identifier === null) {
            throw new \RuntimeException('Stripe financial event has no valid ' . $kind . ' identifier.');
        }

        return $identifier;
    }

    private function stripeOptionalIdentifier(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $identifier = trim((string) $value);
        if ($identifier === '' || strlen($identifier) > 191) {
            return null;
        }

        return $identifier;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return null;
        }

        return (int) $value;
    }

    private function createStripeConnectTransfers(\Stripe\StripeObject $session, \Stripe\Event $event): void
    {
        if ($this->stripeConnect === null || !$this->stripeConnect->enabled()) {
            return;
        }
        $data = $session->toArray();
        if (($data['payment_status'] ?? null) !== 'paid') {
            return;
        }
        $metadata = $this->stripeMetadata($data);
        if (($metadata['stripe_connect'] ?? null) !== 'true') {
            return;
        }
        $cartId = $this->positiveInteger($metadata['cart_id'] ?? null);
        $paymentIntentId = $this->stripeTransactionId($session, 'checkout.session.completed', null);
        if ($cartId === null || $paymentIntentId === null) {
            throw new \RuntimeException('Stripe Connect checkout is missing its cart or payment intent.');
        }
        [$orderId] = $this->financialOrderReference($session);
        if ($orderId === null) {
            throw new \RuntimeException('Stripe Connect checkout order is not available yet.');
        }
        $transferGroup = $this->stripeOptionalIdentifier($metadata['transfer_group'] ?? null) ?? 'dz_cart_' . $cartId;
        $snapshot = $this->checkoutSnapshotForSession($session);
        if ($snapshot !== null) {
            $this->checkoutSnapshots?->bindOrder($snapshot['id'], $orderId);
        }
        $this->stripeConnect->createTransfersForOrder(
            $orderId,
            $paymentIntentId,
            $transferGroup,
            $this->stripeEventTimestamp($event->created ?? null),
            $snapshot['payload'] ?? null,
        );
    }

    /**
     * Confirms an order for a Checkout Session whose payment was already
     * verified by handleWebhook(). The session-level lock and marker make a
     * Stripe retry or the later async success event harmless.
     */
    public function handleCheckoutSessionCompleted(\Stripe\StripeObject $session): ?int
    {
        if (!$this->isPaidCheckoutSession($session)) {
            Log::info('Stripe webhook: unpaid Checkout Session cannot be confirmed.', [
                'checkout_session_id' => (string) ($session->id ?? ''),
            ]);
            return null;
        }

        $snapshot = $this->checkoutSnapshotForSession($session);
        $snapshotPayload = $snapshot['payload'] ?? null;
        $data = $session->toArray();
        $metadata = $this->stripeMetadata($data);
        $cartId = $snapshotPayload === null
            ? $this->positiveInteger($metadata['cart_id'] ?? null) ?? 0
            : (int) $snapshotPayload['cart_id'];
        $customerId = $snapshotPayload === null
            ? $this->positiveInteger($metadata['id_customer'] ?? null)
            : $this->positiveInteger($snapshotPayload['customer_id'] ?? null);
        $guestId = $snapshotPayload === null
            ? $this->positiveInteger($metadata['id_guest'] ?? null)
            : $this->positiveInteger($snapshotPayload['guest_id'] ?? null);
        $carrierId = $snapshotPayload === null
            ? filter_var($metadata['id_carrier'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : (int) $snapshotPayload['carrier_id'];
        $couponCode = $snapshotPayload === null
            ? ($this->stripeOptionalIdentifier($metadata['coupon_code'] ?? null))
            : (($snapshotPayload['coupon_code'] ?? '') === '' ? null : (string) $snapshotPayload['coupon_code']);

        if ($cartId <= 0) {
            Log::warning('Stripe webhook: missing or invalid cart_id in metadata for session ' . $session->id);
            return null;
        }

        // Convert from Stripe's smallest currency unit to the major unit.
        // This implementation supports only two-decimal currencies (e.g. EUR).
        // Zero-decimal currencies (e.g. JPY) must not be divided; validate the currency first.
        $currency = strtolower((string) ($data['currency'] ?? ''));
        if ($currency !== 'eur') {
            Log::error('Stripe webhook: unsupported currency "' . $currency . '" for session ' . $session->id);
            throw new \RuntimeException('Unsupported currency "' . $currency . '" in Stripe session ' . $session->id);
        }

        $amountPaid = $snapshotPayload === null
            ? (($session->amount_total ?? 0) / 100)
            : ((int) $snapshotPayload['totals']['grand_total_cents'] / 100);
        if ($amountPaid <= 0) {
            Log::error('Stripe webhook: missing or zero amount_total for session ' . $session->id);
            throw new \RuntimeException('Invalid amount_total in Stripe session ' . $session->id);
        }

        if ($carrierId === false) {
            Log::error('Stripe webhook: missing id_carrier in metadata for session ' . $session->id . ', cart ' . $cartId);
            throw new \RuntimeException('Missing id_carrier in Stripe session metadata for cart ' . $cartId);
        }

        $checkoutSessionId = $this->checkoutSessionId($session);

        return $this->withCheckoutConfirmationLock($checkoutSessionId, function () use (
            $checkoutSessionId,
            $cartId,
            $customerId,
            $guestId,
            $carrierId,
            $couponCode,
            $amountPaid,
            $session,
            $metadata,
            $snapshot,
            $snapshotPayload,
        ): int {
            if ($this->checkoutConfirmationIsRecorded($checkoutSessionId)) {
                Log::info('Stripe webhook: duplicate paid checkout ignored after confirmation.', [
                    'checkout_session_id' => $checkoutSessionId,
                    'cart_id' => $cartId,
                ]);
                return $cartId;
            }

            // The cache marker protects retries in the usual path. This
            // second lookup keeps a retry safe even if that cache was cleared
            // after PrestaShop already accepted the order confirmation.
            if ($this->orderAlreadyExistsForCart($cartId, $customerId, $guestId)) {
                $this->recordCheckoutConfirmation($checkoutSessionId, $cartId);
                Log::info('Stripe webhook: existing order found for paid checkout.', [
                    'checkout_session_id' => $checkoutSessionId,
                    'cart_id' => $cartId,
                ]);
                return $cartId;
            }

            $customerDetails = $snapshotPayload === null
                ? $this->getCheckoutCustomer($cartId, $customerId, $guestId, $session)
                : $this->getCheckoutCustomerFromSnapshot($snapshotPayload, $session);
            $customerDetails->payment_module = $snapshotPayload === null
                ? $this->metadataPaymentModule($metadata['payment_module'] ?? null)
                : $this->metadataPaymentModule($snapshotPayload['payment_module'] ?? null);
            $customerDetails->create_account = $snapshotPayload === null
                ? $this->metadataBoolean($metadata['create_account'] ?? null, 'create_account')
                : (bool) $snapshotPayload['create_account'];
            $customerDetails->newsletter = $snapshotPayload === null
                ? $this->metadataBoolean($metadata['newsletter'] ?? null, 'newsletter')
                : (bool) $snapshotPayload['newsletter'];
            $email = $customerDetails->email;
            $firstname = $customerDetails->firstname;
            $lastname = $customerDetails->lastname;

            if ($snapshot !== null) {
                $this->orderService->confirmSessionOrder(
                    $cartId,
                    $customerId,
                    $guestId,
                    $carrierId,
                    $couponCode,
                    $email,
                    $firstname,
                    $lastname,
                    $customerDetails,
                    $amountPaid,
                    'Online payment',
                    $snapshot['id'],
                );
            } else {
                $this->orderService->confirmSessionOrder(
                    $cartId,
                    $customerId,
                    $guestId,
                    $carrierId,
                    $couponCode,
                    $email,
                    $firstname,
                    $lastname,
                    $customerDetails,
                    $amountPaid,
                );
            }
            $this->recordCheckoutConfirmation($checkoutSessionId, $cartId);

            try {
                $contactId = $this->mailjetService->createNewContact($email, $firstname, $lastname);
                $this->mailjetService->setContactListSubscription($contactId, env('MAILJET_CLIENTI_LIST_ID', 10663907));
                $this->mailjetService->setContactListSubscription($contactId);
            } catch (\Exception $e) {
                Log::critical('Stripe webhook: failed to create new contact in Mailjet for email ' . $email . ': ' . $e->getMessage());
            }

            Log::info('Stripe webhook: order confirmed for cart ' . $cartId);
            return $cartId;
        });
    }

    protected function withCheckoutConfirmationLock(string $checkoutSessionId, callable $callback): mixed
    {
        $lock = Cache::lock(
            $this->checkoutConfirmationLockKey($checkoutSessionId),
            self::CHECKOUT_CONFIRMATION_LOCK_SECONDS,
        );

        if (!$lock->get()) {
            throw new \RuntimeException('Checkout confirmation is already being processed.');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    protected function checkoutConfirmationIsRecorded(string $checkoutSessionId): bool
    {
        return Cache::has($this->checkoutConfirmationMarkerKey($checkoutSessionId));
    }

    protected function recordCheckoutConfirmation(string $checkoutSessionId, int $cartId): void
    {
        Cache::put(
            $this->checkoutConfirmationMarkerKey($checkoutSessionId),
            $cartId,
            (new DateTimeImmutable('now'))->modify('+' . self::CHECKOUT_CONFIRMATION_MARKER_MINUTES . ' minutes'),
        );
    }

    private function checkoutSessionId(\Stripe\StripeObject $session): string
    {
        $sessionId = $this->stripeOptionalIdentifier($session->toArray()['id'] ?? null);
        if ($sessionId === null || !str_starts_with($sessionId, 'cs_')) {
            throw new \RuntimeException('Stripe Checkout session has no valid identifier.');
        }

        return $sessionId;
    }

    private function orderAlreadyExistsForCart(int $cartId, ?int $customerId, ?int $guestId): bool
    {
        try {
            return $this->orderService->getOrderByCartId($cartId, $customerId, $guestId) !== null;
        } catch (\Throwable) {
            // The cart-to-order reference is a replay safeguard, not the
            // payment source of truth. A transient lookup failure must leave
            // the webhook retryable instead of discarding a paid order.
            return false;
        }
    }

    private function checkoutConfirmationMarkerKey(string $checkoutSessionId): string
    {
        return 'stripe:checkout:confirmed:' . hash('sha256', $checkoutSessionId);
    }

    private function checkoutConfirmationLockKey(string $checkoutSessionId): string
    {
        return 'stripe:checkout:confirmation-lock:' . hash('sha256', $checkoutSessionId);
    }

    /** @return array{id:string,payload:array<string,mixed>}|null */
    private function checkoutSnapshotForSession(\Stripe\StripeObject $session): ?array
    {
        $snapshotId = $this->stripeMetadata($session->toArray())['checkout_snapshot_id'] ?? null;
        if ($snapshotId === null || $snapshotId === '') {
            return null;
        }
        if (!is_string($snapshotId) || $this->checkoutSnapshots === null) {
            throw new \RuntimeException('Checkout snapshot service is unavailable for this Stripe Checkout Session.');
        }
        return $this->checkoutSnapshots->resolveForStripeSession($snapshotId, $session);
    }

    /** @param array<string, mixed> $snapshot */
    private function getCheckoutCustomerFromSnapshot(array $snapshot, \Stripe\StripeObject $stripeSession): object
    {
        $customer = $snapshot['customer'] ?? null;
        if (!is_array($customer)) {
            throw new \RuntimeException('Checkout snapshot customer data is invalid.');
        }

        $shippingAddress = $this->mapStripeAddress($stripeSession->shipping_details?->address ?? null, 'delivery');
        if ($shippingAddress !== null) {
            $customer['delivery_address'] = $shippingAddress;
        }
        $invoiceAddress = $this->mapStripeAddress($stripeSession->customer_details?->address ?? null, 'invoice');
        if ($invoiceAddress !== null) {
            $customer['invoice_address'] = $invoiceAddress;
        }

        if (empty($customer['email']) || empty($customer['firstname']) || empty($customer['lastname'])
            || !$this->isValidAddress($customer['delivery_address'] ?? null)) {
            throw new \RuntimeException('Checkout snapshot customer data is incomplete.');
        }
        return (object) $customer;
    }

    /** Retrieve the checkout identity and delivery address, including unregistered guests. */
    protected function getCheckoutCustomer(
        int $cartId,
        ?int $customerId,
        ?int $guestId,
        ?\Stripe\StripeObject $stripeSession = null
    ): object
    {
        $cached = $this->decryptCachedValue($this->tags(['order-session'])->getFromCache((string) $cartId));
        if (is_array($cached) && array_key_exists('orderSession', $cached)) {
            $cached = $cached['orderSession'];
        }

        if ($cached instanceof OrderSession) {
            $owner = $cached->metadata;
            $customer = $cached->getCustomer()->toArray();
        } elseif (is_array($cached)) {
            $owner = $cached['metadata'] ?? $cached;
            $customer = $cached['customer'] ?? null;
            if ($customer instanceof CustomerEntity) {
                $customer = $customer->toArray();
            }
        } else {
            throw new \RuntimeException('Missing checkout customer data for cart ' . $cartId);
        }

        // A cart may be checked out again: never confirm using a different owner's cached data.
        $cachedCustomerId = (int) ($owner['id_customer'] ?? 0);
        $cachedGuestId = (int) ($owner['id_guest'] ?? 0);
        if (($customerId === null && $guestId === null)
            || $cachedCustomerId !== ($customerId ?? 0)
            || $cachedGuestId !== ($guestId ?? 0)) {
            throw new \RuntimeException('Checkout customer identity mismatch for cart ' . $cartId);
        }
        if (!is_array($customer)) {
            throw new \RuntimeException('Incomplete checkout customer data for cart ' . $cartId);
        }

        $shippingAddress = $this->mapStripeAddress($stripeSession?->shipping_details?->address ?? null, 'delivery');
        if ($shippingAddress !== null) {
            $customer['delivery_address'] = $shippingAddress;
        }
        $invoiceAddress = $this->mapStripeAddress($stripeSession?->customer_details?->address ?? null, 'invoice');
        if ($invoiceAddress !== null) {
            $customer['invoice_address'] = $invoiceAddress;
        }

        if (empty($customer['email']) || empty($customer['firstname']) || empty($customer['lastname'])
            || !$this->isValidAddress($customer['delivery_address'] ?? null)) {
            throw new \RuntimeException('Incomplete checkout customer data for cart ' . $cartId);
        }

        return (object) $customer;
    }

    private function mapStripeAddress(mixed $address, string $kind): ?array
    {
        if ($address === null) {
            return null;
        }
        if ($address instanceof \Stripe\StripeObject) {
            $address = $address->toArray();
        } elseif (is_object($address)) {
            $address = get_object_vars($address);
        }
        if (!is_array($address)) {
            if ($kind === 'invoice') {
                return null;
            }
            throw new \RuntimeException('Invalid Stripe ' . $kind . ' address data.');
        }

        $mapped = [
            'address1' => $address['line1'] ?? null,
            'address2' => $address['line2'] ?? null,
            'city' => $address['city'] ?? null,
            'postcode' => $address['postal_code'] ?? null,
            'state' => $address['state'] ?? null,
            'country' => $address['country'] ?? null,
        ];
        if (!$this->isValidAddress($mapped)) {
            if ($kind === 'invoice') {
                return null;
            }
            throw new \RuntimeException('Incomplete Stripe ' . $kind . ' address data.');
        }

        foreach ($mapped as $field => $value) {
            if ($value !== null && !is_string($value)) {
                if ($kind === 'invoice') {
                    return null;
                }
                throw new \RuntimeException('Invalid Stripe ' . $kind . ' address data.');
            }
        }
        if ($mapped['country'] !== null && !preg_match('/^[a-z]{2}$/i', $mapped['country'])) {
            if ($kind === 'invoice') {
                return null;
            }
            throw new \RuntimeException('Invalid Stripe ' . $kind . ' address data.');
        }

        return $mapped;
    }

    private function isValidAddress(mixed $address): bool
    {
        return is_array($address)
            && is_string($address['address1'] ?? null) && trim($address['address1']) !== ''
            && is_string($address['city'] ?? null) && trim($address['city']) !== ''
            && is_string($address['postcode'] ?? null) && trim($address['postcode']) !== '';
    }

    private function metadataPaymentModule(mixed $paymentModule): ?string
    {
        if ($paymentModule === null || $paymentModule === '') {
            return null;
        }
        if (!is_string($paymentModule) || !preg_match('/^[a-z][a-z0-9_-]*$/i', $paymentModule)) {
            throw new \RuntimeException('Invalid payment module in Stripe metadata.');
        }

        return $paymentModule;
    }

    private function metadataBoolean(mixed $value, string $name): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        if (!is_string($value) || !in_array($value, ['true', 'false'], true)) {
            throw new \RuntimeException('Invalid ' . $name . ' value in Stripe metadata.');
        }

        return $value === 'true';
    }

    /**
     * Process the recovery cart process for a given cart ID.
     * This method is currently a placeholder and should be implemented to handle cart recovery logic.
     */
    public function handleCheckoutSessionExpired(\Stripe\StripeObject $session): void
    {
        // The verified Stripe event is the source of truth for the checkout.
        // Cache entries are JSON-serialized and their metadata is therefore an
        // array; never replace the Stripe metadata with that cache payload.
        $stripeMetadata = $this->stripeMetadata($session->toArray());
        $cartId = isset($stripeMetadata['cart_id']) ? (int) $stripeMetadata['cart_id'] : 0;
        $carrierId = isset($stripeMetadata['id_carrier']) ? (int) $stripeMetadata['id_carrier'] : null;
        $recoveryAttempt = isset($stripeMetadata['recovery_attempt'])
            ? filter_var($stripeMetadata['recovery_attempt'], FILTER_VALIDATE_BOOLEAN)
            : false;

        if ($cartId <= 0) {
            Log::warning('Stripe webhook: missing or invalid cart_id in metadata for expired session ' . $session->id);
            return;
        }

        $cachedSession = $this->decryptCachedValue($this->tags(['order-session'])->getFromCache((string)$cartId));

        if (is_array($cachedSession) && array_key_exists('orderSession', $cachedSession)) {
            $cachedSession = $cachedSession['orderSession'];
        }

        if (is_array($cachedSession)) {
            if (isset($cachedSession['metadata']) && !isset($cachedSession['cart_id'])) {
                $cachedMetadata = $cachedSession['metadata'];
                if ($cachedMetadata instanceof \Stripe\StripeObject) {
                    $cachedMetadata = $cachedMetadata->toArray();
                }
                if (!is_array($cachedMetadata)) {
                    $cachedMetadata = [];
                }
                $cachedSession = [
                    'cart_id' => $cachedMetadata['cart_id'] ?? $cartId,
                    'id_customer' => $cachedMetadata['id_customer'] ?? null,
                    'id_guest' => $cachedMetadata['id_guest'] ?? null,
                    'id_carrier' => $cachedMetadata['id_carrier'] ?? $carrierId,
                    'recovery_attempt' => $cachedMetadata['recovery_attempt'] ?? false,
                    'customer' => $cachedSession['customer'] ?? null,
                ];
            }
            $customerData = $cachedSession['customer'] ?? [
                'email' => $stripeMetadata['customer_email'] ?? null,
                'firstname' => null,
                'lastname' => null,
                'phone' => null,
                'newsletter' => false,
            ];

            if (!$customerData instanceof CustomerEntity && is_array($customerData)) {
                $customerData = CustomerEntity::create($customerData, $this->orderService);
            }

            $cachedSession['customer'] = $customerData;
            $cachedSession = OrderSession::create($cachedSession, $this->orderService);
        }

        if (!$cachedSession instanceof OrderSession) {
            Log::warning('Stripe webhook: no cached order session found for expired cart ' . $cartId . '; skipping recovery and returning 200 OK');
            return;
        }

        $metadataFromCache = $cachedSession->metadata ?? [];
        $customerId = isset($metadataFromCache['id_customer']) && (int) $metadataFromCache['id_customer'] > 0
            ? (int) $metadataFromCache['id_customer']
            : null;
        $guestId = isset($metadataFromCache['id_guest']) && (int) $metadataFromCache['id_guest'] > 0
            ? (int) $metadataFromCache['id_guest']
            : null;
        $customerDetails = $cachedSession->getCustomer();

        if (!is_null($customerId) && !is_null($guestId)) {
            throw new InvalidArgumentException("No customer details retrived from cache");
        }

        if($recoveryAttempt === true) {
            // Skip processing if this is a recovery attempt to avoid infinite loops
            Log::info("Session already attempt");
            return;
        }

        $orderToCreate = $stripeMetadata;
        $orderToCreate['customer'] = $customerDetails->toArray();
        $orderToCreate['id_cart'] = $cartId;
        $orderToCreate['id_carrier'] = $carrierId;
        $orderToCreate['current_state'] = 0;
        $orderToCreate['date_add'] = date('Y-m-d H:i:s');
        $orderToCreate['recovery_attempt'] = true;
        $orderToCreate['expires_at'] = time() + 86400;

        Log::info('Creating order for cart ' . $cartId . ' with customer ID ' . $customerId . ' and guest ID ' . $guestId);
        $cart = $this->orderService->getCartFromId($cartId, $customerId, $guestId);
        $newOrder = OrderEntity::create($orderToCreate, $this->orderService);


        $order = $this->makeOrder($newOrder, $this->orderService, $cart->toArray()['products'] ?? [], $cart->toArray());

        $paymentUrl = $this->stripeService->createPaymentSession($order);

        // send email to customer with payment link and line items
        $amountTotal = $session->amount_total ?? null;
        $recoveryTotal = is_numeric($amountTotal) && (float) $amountTotal >= 0
            ? (float) $amountTotal / 100
            : $order->total();
        $this->mailer->sendRecoveryCartExpired($order->getCustomer()->email, $paymentUrl, $this->getProducts(), (string) $recoveryTotal, $order->getCustomer()->firstname);

        Log::info('Stripe webhook: checkout session expired for cart ' . $cartId);
    }

    /**
     * @param array $cartProducts
     * @return array{name: mixed, photo: string, price: string[]}
     */
    private function lineItems(array $cartProducts): array
    {
        $items = [];
        foreach ($cartProducts as $item) {
            $product = Product::find((int) $item['id_product']);
            $idDefaultImage = ProductEntity::create(['id' => $item['id_product']], $this->orderService)->getImages()[0]->id ?? 0;

            $items[] = [
                'name' => $product->name,
                'photo' => build_product_image_url($idDefaultImage, $product->name, 'small_default'),
                'price' => number_format((float) $product->price, 2, '.', ''),
            ];
        }
        return $items;
    }
}
