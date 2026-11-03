<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PS;

use PS\Webservice\Service\HttpServiceInterface;

class PrestashopConnectorException extends \Exception {

    public function __construct(HttpServiceInterface  $service, ?\Throwable $previous = null) {

    // Upstream bodies, URLs, headers and nested exceptions can contain secrets.
    parent::__construct('Unable to communicate with PrestaShop.', 424);

    }
}
