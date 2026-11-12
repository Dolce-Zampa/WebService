<?php
declare(strict_types=1);

namespace PS\Webservice\Service\Payments;

use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Domain\ObjectInterface;

class PaymentService implements PaymentGatewayInterface
{
    private static ?string $apiKey = null;

    public function __construct(
        private ?\Stripe\StripeClient $stripeClient = null,
        private readonly ?CheckoutSnapshotService $checkoutSnapshots = null,
        private readonly ?StripeCheckoutCustomerService $checkoutCustomers = null,
    )
    {
    }

    public static function setApiKey(string $apiKey): self
    {
        self::$apiKey = $apiKey;
        \Stripe\Stripe::setApiKey($apiKey);
        return new self();
    }

    public function createPaymentSession(OrderSession $orderSession): string
    {
        if (self::$apiKey === null && $this->stripeClient === null) {
            throw new \RuntimeException("Stripe API key not set. Call setApiKey() first.");
        }

        try {
            if (StripeConnectModule::enabled()) {
                $cartId = (string) ($orderSession->metadata['cart_id'] ?? '');
                if (!ctype_digit($cartId) || (int) $cartId < 1) {
                    throw new \RuntimeException('Cannot create a Stripe Connect payment without a cart ID.');
                }
                $orderSession->enableStripeConnect('dz_cart_' . $cartId);
            }
            $metadata = $orderSession->metadata;
            $idempotencyKey = $metadata['checkout_attempt_key'] ?? $orderSession->checkoutAttemptFingerprint();
            if (!is_string($idempotencyKey) || $idempotencyKey === '') {
                throw new \RuntimeException('Stripe checkout idempotency key is invalid.');
            }

            $params = $orderSession->toStripePaymentArray();
            if ($this->checkoutCustomers !== null) {
                // Bank transfers in Checkout require an existing Customer.
                // Resolve it before creating the Session so retries always
                // pass the same `cus_...` identifier.
                $params['customer'] = $this->checkoutCustomers->getOrCreate($orderSession, $this->stripeClient);
            }
            $options = ['idempotency_key' => $idempotencyKey];
            $checkout_session = $this->stripeClient === null
                ? \Stripe\Checkout\Session::create($params, $options)
                : $this->stripeClient->checkout->sessions->create($params, $options);
            $snapshotId = $orderSession->metadata['checkout_snapshot_id'] ?? null;
            if (is_string($snapshotId) && $this->checkoutSnapshots !== null) {
                $this->checkoutSnapshots->bindStripeSession(
                    $snapshotId,
                    $checkout_session->id ?? null,
                    $checkout_session->payment_intent ?? null,
                    $orderSession->metadata['transfer_group'] ?? null,
                );
            }
            
            return $checkout_session->url;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new \RuntimeException("Failed to create Stripe checkout session: " . $e->getMessage());
        }
    }

    /** Only release a gift card hold once Stripe has made the session unpayable. */
    public function expireUnpaidSession(string $sessionId): void
    {
        if (!preg_match('/^cs_[A-Za-z0-9_]{1,188}$/D', $sessionId)) {
            throw new \InvalidArgumentException('Invalid Stripe Checkout Session.');
        }
        $session = $this->retrieveCheckoutSession($sessionId);
        if (($session->status ?? null) === 'expired' && ($session->payment_status ?? null) === 'unpaid') return;
        if (($session->status ?? null) === 'complete' || ($session->payment_status ?? null) === 'paid') {
            throw new CheckoutPaymentAlreadyCompletedException('Checkout has already completed.');
        }
        if (($session->status ?? null) !== 'open' || ($session->payment_status ?? null) !== 'unpaid') {
            throw new \RuntimeException('Stripe Checkout Session cannot be safely expired.');
        }
        try {
            $session = $this->expireCheckoutSession($sessionId);
        } catch (\Stripe\Exception\ApiErrorException $error) {
            // Another cancellation or a payment can win the race with expiration.
            $session = $this->retrieveCheckoutSession($sessionId);
        }
        if (($session->status ?? null) === 'complete' || ($session->payment_status ?? null) === 'paid') {
            throw new CheckoutPaymentAlreadyCompletedException('Checkout has already completed.');
        }
        if (($session->status ?? null) !== 'expired' || ($session->payment_status ?? null) !== 'unpaid') {
            throw new \RuntimeException('Stripe Checkout Session could not be safely expired.');
        }
    }

    protected function retrieveCheckoutSession(string $sessionId): \Stripe\Checkout\Session
    {
        return $this->stripeClient === null ? \Stripe\Checkout\Session::retrieve($sessionId)
            : $this->stripeClient->checkout->sessions->retrieve($sessionId, []);
    }

    protected function expireCheckoutSession(string $sessionId): \Stripe\Checkout\Session
    {
        return $this->stripeClient === null ? \Stripe\Checkout\Session::retrieve($sessionId)->expire()
            : $this->stripeClient->checkout->sessions->expire($sessionId, []);
    }

    public function getPaymentUrl(OrderSession $orderSession, ObjectInterface $entity): string 
    {
        $paymentLink = \Stripe\PaymentLink::create([
            'line_items' => $orderSession->getLineItems(),
            'after_completion' => [
                'type' => 'redirect',
                'redirect' => ['url' => env("APP_URL").'/aretigiani/premium/success'],
            ],
            'metadata' => $entity->toArray(), // Store the entity data in metadata for later use
        ]);

        return $paymentLink->url;
    }
}
