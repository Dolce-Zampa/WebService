<?php
declare(strict_types=1);

namespace PS\Webservice\Service;

use PS\Webservice\Domain\Object\PayloadServiceData;
use PS\Webservice\Domain\Object\WebserviceConfig;
use Slim\Http\Interfaces\ResponseInterface;

interface HttpServiceInterface {

    public function __construct(WebserviceConfig $config);

    public function setUrl(string $url): void;

    public function invoke(string $method, array|PayloadServiceData $data = []): self;

    public function response(): ResponseInterface;

    public function getBody(): string;

    public function toArray(): array;

    public function getHttpCode(): int;

    public function failed(): bool;

    public function onError(callable $callback): void;  

    public function getConfig(): WebserviceConfig;
}