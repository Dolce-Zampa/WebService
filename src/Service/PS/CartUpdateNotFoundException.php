<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PS;

/** The upstream update returned 404; the cart must still be checked separately. */
class CartUpdateNotFoundException extends PrestashopConnectorException
{
}
