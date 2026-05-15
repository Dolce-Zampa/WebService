<?php

declare(strict_types=1);

namespace PS\Webservice\Service\PS;

use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Service\HttpServiceInterface;
use Illuminate\Support\Facades\Log;

class Customer extends PrestashopService implements PrestashopServiceInterface
{
    public function register(CustomerEntity $customer): HttpServiceInterface
    {
        return $this->post('/register?noc_cache=1', $customer, 'register', $customer->toArray()['customer']['email'] ?? null);
    }

    public function createCustomer(CustomerEntity $customer): HttpServiceInterface
    {
        return $this->post('/customers?noc_cache=1', $customer, 'create', $customer->toArray()['customer']['email'] ?? null);
    }

    /**
     * @param array<string, mixed> $credentials
     */
    public function login(array $credentials): HttpServiceInterface
    {
        return $this->post('/login?noc_cache=1', $credentials, 'login', $credentials['email'] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function contact(array $payload): HttpServiceInterface
    {
        return $this->post('/contact?noc_cache=1', $payload, 'send contact request', $payload['email'] ?? null);
    }

    /**
     * @param array<string, mixed>|CustomerEntity $payload
     */
    private function post(string $url, array|CustomerEntity $payload, string $action, ?string $email = null): HttpServiceInterface
    {
        $this->httpService->setUrl($url);

        try {
            $response = $this->httpService->invoke('POST', $payload);
        } catch (\Exception $e) {
            Log::error("Exception occurred while attempting to {$action} customer {$email}: " . $e->getMessage());
            throw new \RuntimeException("Unable to {$action} customer", 500, $e);
        }

        return $response;
    }
}
