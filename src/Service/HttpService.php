<?php
declare(strict_types=1);

namespace PS\Webservice\Service;

use PS\Webservice\Domain\Object\PayloadServiceData;
use PS\Webservice\Domain\Object\WebserviceConfig;
use PS\Webservice\Service\HttpServiceInterface;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Slim\Http\Interfaces\ResponseInterface;

class HttpService implements HttpServiceInterface
{

    private WebserviceConfig $config;
    private string $api;
    private \GuzzleHttp\Psr7\Response $response;
    private int $httpCode;

    public function __construct(WebserviceConfig $config)
    {
        $this->config = $config;
    }

    public function setUrl(string $url): void
    {
        $this->api = $this->config->api($url);
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
        $data = $data->toArray();
        try {
            $config = $this->config;
            $stream = new Client($config->toArray());

            $options = [
                'verify' => false, //FIXME: Riattiva sempre in produzione!
                'timeout' => 10,   // È buona norma impostare un timeout
            ];

            if (!empty($data)) {
                // Se il metodo è POST/PUT, usiamo 'json'
                $options['json'] = $data;
            }

            Log::debug("Invoking HTTP request: {$method} {$this->api} with data: " . json_encode($data));
            $response = $stream->request($method, $this->api, $options);

            $this->response = $response;
            $this->httpCode = $response->getStatusCode();            

        } catch (\GuzzleHttp\Exception\RequestException $e) {
            // Gestisci l'errore (log, alert, ecc.)
            $this->httpCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 500;
            $this->response = $e->getResponse();
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
    public function getBody(): string
    {
        return $this->response->getBody()->getContents();
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    public function failed(): bool
    {
        return $this->httpCode >= 400;
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
        if ($this->httpCode >= 400) {
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
}
