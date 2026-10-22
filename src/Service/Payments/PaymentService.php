<?php
declare(strict_types=1);

namespace PS\Webservice\Service\Payments;

use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Domain\ObjectInterface;

class PaymentService implements PaymentGatewayInterface
{
    private static ?string $apiKey = null;

    public function __construct(private ?\Stripe\StripeClient $stripeClient = null)
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

            $params = $orderSession->toArray();
            $options = ['idempotency_key' => $idempotencyKey];
            $checkout_session = $this->stripeClient === null
                ? \Stripe\Checkout\Session::create($params, $options)
                : $this->stripeClient->checkout->sessions->create($params, $options);
            
            return $checkout_session->url;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new \RuntimeException("Failed to create Stripe checkout session: " . $e->getMessage());
        }
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
