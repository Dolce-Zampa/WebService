<?php
declare(strict_types=1);

namespace PS\Webservice\Service\Payments;

use PS\Webservice\Domain\Object\Discount;
use PS\Webservice\Domain\Object\OrderSession;

class PaymentService implements PaymentGatewayInterface
{
    private static ?string $apiKey = null;

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
            $checkout_session = \Stripe\Checkout\Session::create(
                $orderSession->toArray()
            );
            
            return $checkout_session->url;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new \RuntimeException("Failed to create Stripe checkout session: " . $e->getMessage());
        }
    }
}