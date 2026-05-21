<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Service\PS\Customer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CustomerController extends Controller
{
    private Customer $customerService;

    public function __construct(Customer $customerService)
    {
        $this->customerService = $customerService;
    }

    public function register(Request $request, Response $response, array $argv): Response
    {
        $payload = $this->requireArrayPayload($request->getParsedBody());

        $customer = $this->customerService->register(CustomerEntity::create($payload, $this->customerService));

        return $this->buildServiceResponse($customer, 201);
    }

    public function createCustomer(Request $request, Response $response, array $argv): Response
    {
        $payload = $this->requireArrayPayload($request->getParsedBody());
        $this->validateCustomerPayload($payload);

        $customer = $this->customerService->createCustomer(CustomerEntity::create($payload, $this->customerService));

        return $this->buildServiceResponse($customer, 201);
    }

    public function login(Request $request, Response $response, array $argv): Response
    {
        $payload = $this->requireArrayPayload($request->getParsedBody());
        $this->validateLoginPayload($payload);

        $loginResponse = $this->customerService->login($payload);

        return $this->buildServiceResponse($loginResponse);
    }

    public function contact(Request $request, Response $response, array $argv): Response
    {
        $payload = $this->requireArrayPayload($request->getParsedBody());
        $this->validateContactPayload($payload);

        $contactResponse = $this->customerService->contact($payload);

        return $this->buildServiceResponse($contactResponse, 201);
    }

    protected function validateCustomerPayload(array $payload): bool
    {

        $customer = $payload['customer'];
        $requiredCustomerFields = ['email', 'password', 'firstname', 'lastname', 'delivery_address'];

        foreach ($requiredCustomerFields as $field) {
            if (!array_key_exists($field, $customer)) {
                throw new \InvalidArgumentException("Missing required customer field: {$field}", 400);
            }
        }

        $stringFields = ['email', 'password', 'firstname', 'lastname'];
        foreach ($stringFields as $field) {
            if (!is_string($customer[$field]) || trim($customer[$field]) === '') {
                throw new \InvalidArgumentException("Field {$field} must be a non-empty string", 400);
            }
        }

        if (filter_var($customer['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Field email must be a valid email address', 400);
        }

        if (array_key_exists('newsletter', $customer) && !is_bool($customer['newsletter'])) {
            throw new \InvalidArgumentException('Field newsletter must be a boolean', 400);
        }

        if (!is_array($customer['delivery_address'])) {
            throw new \InvalidArgumentException('Field delivery_address must be an object', 400);
        }

        $this->validateDeliveryAddress($customer['delivery_address']);

        return true;
    }

    protected function validateLoginPayload(array $payload): bool
    {
        foreach (['email', 'password'] as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new \InvalidArgumentException("Missing required field: {$field}", 400);
            }
        }

        if (!is_string($payload['email']) || trim($payload['email']) === '') {
            throw new \InvalidArgumentException('Field email must be a non-empty string', 400);
        }

        if (filter_var($payload['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Field email must be a valid email address', 400);
        }

        if (!is_string($payload['password']) || trim($payload['password']) === '') {
            throw new \InvalidArgumentException('Field password must be a non-empty string', 400);
        }

        return true;
    }

    protected function validateContactPayload(array $payload): bool
    {
        foreach (['email', 'message'] as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new \InvalidArgumentException("Missing required field: {$field}", 400);
            }
        }

        if (!is_string($payload['email']) || trim($payload['email']) === '') {
            throw new \InvalidArgumentException('Field email must be a non-empty string', 400);
        }

        if (filter_var($payload['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Field email must be a valid email address', 400);
        }

        if (!is_string($payload['message']) || trim($payload['message']) === '') {
            throw new \InvalidArgumentException('Field message must be a non-empty string', 400);
        }

        if (isset($payload['subject']) && (!is_string($payload['subject']) || trim($payload['subject']) === '')) {
            throw new \InvalidArgumentException('Field subject must be a non-empty string when provided', 400);
        }

        foreach (['firstname', 'lastname'] as $field) {
            if (isset($payload[$field]) && (!is_string($payload[$field]) || trim($payload[$field]) === '')) {
                throw new \InvalidArgumentException("Field {$field} must be a non-empty string when provided", 400);
            }
        }

        foreach (['id_contact', 'id_customer', 'id_order'] as $field) {
            if (isset($payload[$field]) && (!is_int($payload[$field]) || $payload[$field] <= 0)) {
                throw new \InvalidArgumentException("Field {$field} must be a positive integer when provided", 400);
            }
        }

        return true;
    }

    /**
     * @param mixed $payload
     * @return array<string, mixed>
     */
    private function requireArrayPayload(mixed $payload): array
    {
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid payload format', 400);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $deliveryAddress
     */
    private function validateDeliveryAddress(array $deliveryAddress): void
    {
        $requiredFields = ['alias', 'address1', 'city', 'postcode', 'id_country', 'phone_mobile'];

        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $deliveryAddress)) {
                throw new \InvalidArgumentException("Missing required delivery_address field: {$field}", 400);
            }
        }

        foreach (['alias', 'address1', 'city', 'postcode', 'phone_mobile'] as $field) {
            if (!is_string($deliveryAddress[$field]) || trim($deliveryAddress[$field]) === '') {
                throw new \InvalidArgumentException("Field {$field} in delivery_address must be a non-empty string", 400);
            }
        }

        if (!is_int($deliveryAddress['id_country']) || $deliveryAddress['id_country'] <= 0) {
            throw new \InvalidArgumentException('Field id_country in delivery_address must be a positive integer', 400);
        }
    }

    private function buildServiceResponse(\PS\Webservice\Service\HttpServiceInterface $serviceResponse, int $successCode = 200): Response
    {
        $statusCode = $serviceResponse->failed() ? $serviceResponse->getHttpCode() : $successCode;

        return response($serviceResponse->toArray(), $statusCode);
    }
}
