<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PS;

use PS\Webservice\Service\HttpServiceInterface;

class PrestashopConnectorException extends \Exception {

    public function __construct(HttpServiceInterface  $service, ?\Throwable $previous = null) {

    $message = "An error occurrend while connecting to prestashop server ---- ";
    $details = [
        'error' => is_null($previous) ? $service->getBody() : $previous->getMessage(),
        'config' => $service->getConfig()->toArray()
    ];

    parent::__construct($message . json_encode($details), 424, $previous);

    }
}