<?php

declare(strict_types=1);

namespace PS\Webservice\Service\Shipping;

/** Does not include response bodies, URLs or credentials in the exception message. */
final class PacklinkException extends \RuntimeException
{
    public function __construct(public readonly string $operation, public readonly ?int $status = null)
    {
        parent::__construct('Packlink request failed: ' . $operation . ($status === null ? '' : ' (HTTP ' . $status . ')'));
    }
}
