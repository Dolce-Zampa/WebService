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
        return $this->post('/login?no_cache=1', $credentials, 'login', $credentials['email'] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function contact(array $payload): HttpServiceInterface
    {
        return $this->post('/contact?noc_cache=1', $payload, 'send contact request', $payload['email'] ?? null);
    }

    public function getAccount(int $customerId): HttpServiceInterface
    {
        return $this->invoke('GET', "/account?id_customer={$customerId}", [], 'retrieve customer account');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateAccount(int $customerId, array $payload): HttpServiceInterface
    {
        $payload['id_customer'] = $customerId;
        return $this->invoke('PUT', '/account?noc_cache=1', $payload, 'update customer account');
    }

    public function getAddresses(int $customerId): HttpServiceInterface
    {
        return $this->invoke('GET', "/addresses?id_customer={$customerId}", [], 'retrieve customer addresses');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateAddresses(int $customerId, array $payload): HttpServiceInterface
    {
        $payload['id_customer'] = $customerId;
        return $this->invoke('PUT', '/addresses?noc_cache=1', $payload, 'update customer addresses');
    }

    public function logout(): HttpServiceInterface
    {
        return $this->invoke('POST', '/logout?noc_cache=1', [], 'logout customer');
    }

    /**
     * @param array<string, mixed>|CustomerEntity $payload
     */
    private function post(string $url, array|CustomerEntity $payload, string $action, ?string $email = null): HttpServiceInterface
    {
        return $this->invoke('POST', $url, $payload, $action, $email);
    }

    /**
     * @param array<string, mixed>|CustomerEntity $payload
     */
    private function invoke(string $method, string $url, array|CustomerEntity $payload, string $action, ?string $email = null): HttpServiceInterface
    {
        $this->httpService->setUrl($url);

        try {
            $requestPayload = $payload;
            if (strtoupper($method) === 'GET') {
                $requestPayload = [];
            }
            $response = $this->httpService->invoke($method, $requestPayload);
        } catch (\Exception $e) {
            Log::error("Exception occurred while attempting to {$action} customer {$email}: " . $e->getMessage());
            throw new \RuntimeException("Unable to {$action}", 500, $e);
        }

        return $response;
    }
}
