<?php
declare(strict_types=1);

namespace PS\Webservice\Http\Controller;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Facades\AwsCognitoClient;
use PS\Webservice\Service\Auth\AuthService;
use PS\Webservice\Service\PS\Customer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CustomerController extends Controller
{
    private Customer $customerService;
    private AuthService $authService;
    private const CHALLENGE_REQUEST_NEW_PASSWORD = 'NEW_PASSWORD_REQUIRED';
    private const PASSWORD_VALIDATION = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/';

    public function __construct(Customer $customerService, AuthService $authService)
    {
        $this->customerService = $customerService;
        $this->authService = $authService;
    }

    public function register(Request $request, Response $response, array $argv): Response
    {
        $payload = $this->requireArrayPayload($request->getParsedBody());
        $this->validateCustomerRegistrationPayload($payload);

        $name = trim(((string) $payload['firstname']) . ' ' . ((string) $payload['lastname']));
        $cognitoPayload = [
            'name' => $name,
            'email' => (string) $payload['email'],
            'password' => (string) $payload['password'],
        ];

        try {
            $cognito = $this->authService->signUp($request);

            if ($cognito === false) {
                return response(['message' => 'Cognito signup failed'], 400);
            }

        } catch (\Throwable $e) {
            Log::error('Customer Cognito signup failed: ' . $e->getMessage());
            return response(['message' => 'Unable to register customer'], 400);
        }

        try {
            $customer = $this->customerService->register(CustomerEntity::create($payload, $this->customerService));
            if (!$customer instanceof CustomerEntity) {
                AwsCognitoClient::deleteUser($cognitoPayload['email']);
                return response(['message' => 'Unable to register customer'], 400);
            }

            $sub = $this->extractCognitoAttribute($cognito['access_token'], 'sub');
            return response([
                'customer' => $customer->toArray(),
                'cognito_sub' => $sub,
            ], 201);
        } catch (\Throwable $e) {
            AwsCognitoClient::deleteUser($cognitoPayload['email']);
            Log::error('Customer PrestaShop register failed after Cognito signup: ' . $e->getMessage());
            return response(['message' => 'Unable to register customer'], 500);
        }
    }

    public function createCustomer(Request $request, Response $response, array $argv): Response
    {
        $payload = $this->requireArrayPayload($request->getParsedBody());
        $this->validateCustomerPayload($payload);

        $customer = $this->customerService->createCustomer(CustomerEntity::create($payload, $this->customerService));

        if (!$customer instanceof CustomerEntity) {
            return response(['message' => 'Unable to create customer'], 400);
        }

        return response($customer->toArray(), 201);
    }

    public function login(Request $request, Response $response, array $argv): Response
    {
        $payload = $this->requireArrayPayload($request->getParsedBody());
        $this->validateLoginPayload($payload);

        try {
            $cognitoAuth = $this->authService->authenticate($request);
            if (($cognitoAuth['error'] ?? false) !== false || $cognitoAuth === false) {
                return response(['message' => 'Invalid credentials'], 401);
            }
        } catch (\Throwable $e) {
            Log::error('Customer Cognito login failed: ' . $e->getMessage());
            return response(['message' => 'Invalid credentials'], 401);
        }

        // check for challenge request
        if ($cognitoAuth === self::CHALLENGE_REQUEST_NEW_PASSWORD) {
            return response([
                'result' => 'challenge',
                'challenge' => self::CHALLENGE_REQUEST_NEW_PASSWORD,
                'challenge_parameters' => $cognitoAuth['challenge-parameters'] ?? [],
                'session' => $cognitoAuth['session'] ?? null,
            ], 200);
        }

        $customer = $this->customerService->getAccount(3)->toArray();
        $isSeller = (bool) $this->extractCognitoAttribute($cognitoAuth['id_token'], 'custom:seller');

        if($customer['success'] === false) {
            Log::error('Customer PrestaShop account retrieval failed after Cognito login', [
                'customer_id' => 3,
                'error' => $customer['message'] ?? 'Unknown error',
            ]);
            return response(['message' => 'Unable to retrieve customer account'], 500);
        }

        return response([
            'token' => $cognitoAuth['access_token'] ?? null,
            'message' => 'Login successful',
            'customer' => array_merge($customer['data']['customer'], ['is_seller' => $isSeller], ['addresses' => $customer['data']['addresses'] ]),
        ]);
    }

    public function contact(Request $request, Response $response, array $argv): Response
    {
        $payload = $this->requireArrayPayload($request->getParsedBody());
        $this->validateContactPayload($payload);

        $contactResponse = $this->customerService->contact($payload);

        return $this->buildServiceResponse($contactResponse, 201);
    }

    public function getAccount(Request $request, Response $response, array $argv): Response
    {
        $customerId = (int) ($argv['customerId'] ?? 0);
        if ($customerId <= 0) {
            throw new \InvalidArgumentException('Invalid customer id', 400);
        }

        $serviceResponse = $this->customerService->getAccount($customerId);
        try {
            $getAccountFromCognito = $this->authService->check($request);
        } catch (\Throwable $e) {
            Log::error('Customer Cognito check failed: ' . $e->getMessage());
            return response(['message' => 'Unable to retrieve customer account'], 401);
        }

        return $this->buildServiceResponse($serviceResponse);
    }

    public function updateAccount(Request $request, Response $response, array $argv): Response
    {
        $customerId = (int) ($argv['customerId'] ?? 0);
        if ($customerId <= 0) {
            throw new \InvalidArgumentException('Invalid customer id', 400);
        }

        $payload = $this->requireArrayPayload($request->getParsedBody());
        $serviceResponse = $this->customerService->updateAccount($customerId, $payload);
        return $this->buildServiceResponse($serviceResponse);
    }

    public function getAddresses(Request $request, Response $response, array $argv): Response
    {
        $customerId = (int) ($argv['customerId'] ?? 0);
        if ($customerId <= 0) {
            throw new \InvalidArgumentException('Invalid customer id', 400);
        }

        $serviceResponse = $this->customerService->getAddresses($customerId);
        $data = $serviceResponse->toArray();

        //FIXME: delivery_address and invoice_address are hardcoded to the first two addresses. This should be improved to select the correct addresses based on the customer's preferences or default settings.
        return response([
            'delivery_address' => $data['data']['addresses'][0],
            'invoice_address' => $data['data']['addresses'][1] ?? $data['data']['addresses'][0],
        ]);
    }

    public function updateAddresses(Request $request, Response $response, array $argv): Response
    {
        $customerId = (int) ($argv['customerId'] ?? 0);
        if ($customerId <= 0) {
            throw new \InvalidArgumentException('Invalid customer id', 400);
        }

        $payload = $this->requireArrayPayload($request->getParsedBody());
        $serviceResponse = $this->customerService->updateAddresses($customerId, $payload);
        return $this->buildServiceResponse($serviceResponse);
    }

    public function logout(Request $request, Response $response, array $argv): Response
    {
        $serviceResponse = $this->customerService->logout();
        return $this->buildServiceResponse($serviceResponse);
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

    protected function validateCustomerRegistrationPayload(array $payload): bool
    {
        foreach (['email', 'password', 'firstname', 'lastname'] as $field) {
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

        if (!is_string($payload['firstname']) || trim($payload['firstname']) === '') {
            throw new \InvalidArgumentException('Field firstname must be a non-empty string', 400);
        }

        if (!is_string($payload['lastname']) || trim($payload['lastname']) === '') {
            throw new \InvalidArgumentException('Field lastname must be a non-empty string', 400);
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

        return response($serviceResponse->toArray()['data'], $statusCode);
    }

    private function extractCognitoAttribute(string $accessToken, string $attributeName): ?string
    {
        $attributes = AwsCognitoClient::decodeAccessToken($accessToken);
        if(isset($attributes[$attributeName])) {
            return $attributes[$attributeName];
        }

        return null;
    }

    public function resetPassword(Request $request, Response $response, array $argv): Response
    {
        $bodyParams = $request->getParsedBody();
        Validator::validate($bodyParams, [
                'new_password' => 'required|confirmed|min:8|max:64|regex:' . self::PASSWORD_VALIDATION,
                'token' => 'required|string|max:255',
            ]);

        $reset = $this->authService->resetPasswordWithToken($bodyParams['new_password'], $bodyParams['token']);
        if($reset === false) {
            return response(['message' => 'Unable to reset password'], 400);
        }

        return response(['message' => 'Password reset successful'], 200);
    }

    public function sendResetPassword(Request $request, Response $response, array $argv): Response
    {
        $bodyParams = $request->getParsedBody();
        Validator::validate($bodyParams, [
            'email' => 'required|email|max:64',
        ]);

        $this->authService->sendResetPasswordMail($bodyParams['email']);
      
        return response(['message' => 'Reset password email sent successfully'], 200);
    }
}
