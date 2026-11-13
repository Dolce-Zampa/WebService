<?php
declare(strict_types=1);

namespace PS\Webservice\Domain\Object;

use InvalidArgumentException;

final class WebserviceConfig {

    private readonly string $base_uri;

    private string $api;

    private array $header = [];

    private array $queryParams = [];

    public function __construct(string $domain, array $headers = [])
    {
        $this->base_uri = $domain;
        $this->api = $domain;
        $this->header = $headers;
    }

    public function __get(string $name): mixed 
    {
        if(!isset($this->$name)) {
            return null;
        }
        return $this->$name;
    }

    public function api(string $api): string
    {
        return $this->api . $api;
    }

    public function moduleController(string $controller): string
    {
        $base = rtrim($this->base_uri, '/');
        if (!str_ends_with($base, '/api')) {
            throw new \LogicException('PrestaShop API base URL must end in /api.');
        }

        return substr($base, 0, -4) . '/index.php?' . http_build_query([
            'fc' => 'module',
            'module' => 'webserviceapi',
            'controller' => $controller,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function toArray(): array
    {
        return [
            'base_uri' => $this->base_uri,
            'headers' => $this->header
        ];
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    public function addQueryParams(array $params): void
    {
        $this->queryParams = array_merge($this->queryParams, $params);
    }

    public function getQueryParams(): string
    {
        if(empty($this->queryParams)) {
            return '';
        }
        return "&" . http_build_query($this->queryParams);
    }   

    public function getHeaders(): mixed
    {
        return $this->header;
    }
}
