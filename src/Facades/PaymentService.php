<?php
declare(strict_types=1);

namespace PS\Webservice\Facades;

/**
 * @method static \PS\Webservice\Service\Payments\PaymentService setApiKey(string $apiKey)
 * @method string createPaymentSession(\PS\Webservice\Domain\Object\OrderSession $orderSession)
 * @method \ getPaymentUrl(\PS\Webservice\Domain\Object\OrderSession $priceId, \PS\Webservice\Domain\ObjectInterface $entity)
 * 
 * @see \PS\Webservice\Service\Payments\PaymentService
 */

final class PaymentService extends \Illuminate\Support\Facades\Facade
{
    protected static function getFacadeAccessor()
    {
        return 'payment-service';
    }
}