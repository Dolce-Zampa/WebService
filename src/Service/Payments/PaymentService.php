<?php
declare(strict_types=1);

namespace PS\Webservice\Service\Payments;

use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Domain\ObjectInterface;

class PaymentService implements PaymentGatewayInterface
{
    private static ?string $apiKey = null;

    public function __construct(private readonly ?CheckoutSnapshotService $checkoutSnapshots = null)
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
        if (self::$apiKey === null) {
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
            $checkout_session = \Stripe\Checkout\Session::create(
                $orderSession->toArray()
            );
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
