<?php
declare(strict_types=1);

namespace PS\Webservice\Service\GiftCard;

final class GiftCardApiException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $httpStatus)
    {
        parent::__construct('Gift card operation failed: ' . $errorCode);
    }
}
