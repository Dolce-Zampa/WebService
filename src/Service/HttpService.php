<?php
declare(strict_types=1);

namespace PS\Webservice\Service;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use PS\Webservice\Domain\Object\PayloadServiceData;
use PS\Webservice\Domain\Object\WebserviceConfig;
use PS\Webservice\Service\HttpServiceInterface;
use Slim\Http\Interfaces\ResponseInterface;

class HttpService implements HttpServiceInterface
{
    private WebserviceConfig $config;
    private string $api;
    private ?\GuzzleHttp\Psr7\Response $response = null;
    private int $httpCode;
    private string $body = '';

    private array $multipartData = [];


    public function __construct(WebserviceConfig $config)
    {
        $this->config = $config;
    }

    public function setUrl(string $url): void
    {
        $this->api = $this->config->api($url);
    }

    public function setQueryParams(array $params): void
    {
        $this->config->addQueryParams($params);
    }

    /**
     * Invokes an HTTP request with the specified method and optional data.
     *
     * @param string $method The HTTP method to use for the request (e.g., 'GET', 'POST', 'PUT', 'DELETE').
     * @param array | PayloadServiceData $data Optional. An associative array of data to be sent with the request. Default is an empty array.
     *
     * @return self
     */
    public function invoke(string $method, array | PayloadServiceData $data = []): self
    {
        if($data instanceof PayloadServiceData) {
            $data = $data->toArray();
        }
        
        try {
            $config = $this->config;
            $stream = new Client($config->toArray());

            $options = [
                'verify' => true,
                // Never forward authentication headers to a redirect target.
                'allow_redirects' => false,
                'timeout' => 30,   // È buona norma impostare un timeout
                'headers' => $this->config->getHeaders(),
            ];

            if (!empty($data)) {
                // Se il metodo è POST/PUT, usiamo 'json'
                $options['json'] = $data;
            }

            if(!empty($this->multipartData)) {
                $options['multipart'] = $this->multipartData;
            }

            Log::debug('Outgoing HTTP request', ['method' => $method]);
            $response = $stream->request($method, $this->api . $this->config->getQueryParams(), $options);

            if(is_null($response)) {
                throw new \RuntimeException("HTTP request failed: No response received.");
            }

            $this->response = $response;
            $this->body = $response->getBody()->getContents();
            $this->httpCode = $response->getStatusCode();   

        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $response = $e->getResponse();
            $resp = $response ? $response->getBody()->getContents() : '';
            $uri = $e->getRequest()->getUri()->withUserInfo('')->withQuery('')->withFragment('');
            Log::critical('Outgoing HTTP request failed', [
                'method' => $e->getRequest()->getMethod(),
                'url' => (string) $uri,
                'status' => $response?->getStatusCode(),
                'response' => $resp,
            ]);
            $this->httpCode = $response?->getStatusCode() ?? 500;
            $this->response = $response;
            $this->body = $resp;
        }

        return $this;
    }

    /**
     * Get the HTTP response.
     *
     * @return ResponseInterface The HTTP response object.
     */
    public function response(): ResponseInterface
    {
        return $this->response;
    }

    /**
     * Get the response body as a string.
     *
     * @return string The response body.
     */
    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    public function failed(): bool
    {
        return $this->httpCode >= 300;
    }

    /**
     * Registers a callback function to be executed when an error occurs.
     *
     * @param callable $callback The callback function to execute on error.
     *                           The callback will receive error details as parameters.
     * @return void
     */
    public function onError(callable $callback): void
    {
        if ($this->httpCode >= 300) {
            $callback($this->response);
        }
    }

    public function toArray(): array
    {
        $body = $this->getBody();
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("Failed to decode JSON response: " . json_last_error_msg());
            return [];
        }
        return $data;
    }

    public function getConfig(): WebserviceConfig
    {
        return $this->config;
    }

    public function setMultipartData(array $multipartData): void
    {
        $this->multipartData = $multipartData;
    }

    public function getUrl(): string
    {
        return $this->api ?? '';
    }
}
