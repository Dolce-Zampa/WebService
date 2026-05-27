<?php
declare(strict_types=1);

namespace PS\Webservice\Service\Payments;

use PS\Webservice\Domain\Object\OrderSession;
use PS\Webservice\Service\PS\Order;

class CodPaymentService implements PaymentGatewayInterface
{
    public static function setApiKey(string $apiKey): self
    {
        // no API key needed for COD, but we implement the method to satisfy the interface
        return new static();
    }

    public function createPaymentSession(OrderSession $orderSession): string
    {
        $metadata = $orderSession->metadata;
        $customer = json_decode($metadata['customer']);

        $orderSession->service()->confirmSessionOrder(
            $metadata['cart_id'],
            $metadata['id_customer'],
            $metadata['id_guest'],
            $metadata['id_carrier'],
            $metadata['coupon_code'],
            $customer->email,
            $customer->firstname,
            $customer->lastname,
            $customer,
            $orderSession->total(),
            'Cash on Delivery'
        );
        // must return a URL to redirect
        return env('STRIPE_SUCCESS_URL', 'http://localhost:3000/checkout/success');
    }
}