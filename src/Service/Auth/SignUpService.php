<?php

namespace PS\Webservice\Service\Auth;

/** ########## DOCUMENTATION
 * 
 * This class is responsible for handling the sign up functionality.
 * follow these steps to signup user
 * - 1. create user
 * - 2. create workspace entry
 * - 3. create account entry
 * - 4. create default settings
 */

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use malirobot\AwsCognito\Exception\UsernameExistsException;
use PS\Webservice\Domain\Models\PS\Manufacturers\Manufacturer;
use PS\Webservice\Domain\ObjectInterface;
use PS\Webservice\Facades\AwsCognitoClient;
use PS\Webservice\Facades\Repositories;
use PS\Webservice\Traits\AuthFlow;
use PS\Webservice\Traits\RegistersUsers;
use PS\Webservice\Traits\UseCache;

class SignUpService extends UserService
{
    use RegistersUsers, AuthFlow, UseCache;

    const URL_SIGNUP_CONFIRM = '/app/auth/confirm/';

    /**
     * Handles the sign up functionality.
     * 
     * "{"User":{"Username":"12954434-c091-7097-0301-07a456e327df","Attributes":[{"Name":"email","Value":"marco.defelice890@gmail.com"},{"Name":"email_verified","Value":"true"},{"Name":"sub","Value":"12954434-c091-7097-0301-07a456e327df"}],"UserCreateDate":"2026-07-16T07:38:31+00:00","UserLastModifiedDate":"2026-07-16T07:38:31+00:00","Enabled":true,"UserStatus":"FORCE_CHANGE_PASSWORD"},"@metadata":{"statusCode":200,"effectiveUri":"https:\/\/cognito-idp.eu-west-1.amazonaws.com\/","headers":{"date":"Thu, 16 Jul 2026 07:38:31 GMT","content-type":"application\/x-amz-json-1.1","content-length":"359","connection":"keep-alive","x-amzn-requestid":"69de265d-be04-4bc1-ba5d-94ea099e365a"},"transferStats":{"http":[[]]}}}"
     *
    * @param ObjectInterface $data Signup payload (Request, Collection or array).
     * @return array|bool
     */
    public function signUp(ObjectInterface $data): array|bool
    {
        $isSellerSignup = (bool) $data->is_seller;
        $isNewUser = false;
        $sub = null;
        $authToken = $data->auth_token;
        $newCustomer = $data;

        try {
            if(is_null($authToken) || trim((string) $authToken) === '') {
                $cognito = $this->createCognitoUser($data);
                if($cognito['error'] ?? false) {
                    Log::error("Cognito user creation failed: " . json_encode($cognito));
                    return false;
                }
                $sub = $cognito['User']['Username'];
                $isNewUser = true; // FIXME: This should be determined based on the response from Cognito, not just the presence of an auth token.
            } else {
                // If auth token is provided, we assume the user already exists in Cognito and we skip the creation step.
                $decodedToken = AwsCognitoClient::decodeAccessToken((string) $authToken);
                $sub = $decodedToken['sub'];
            }

            //create user in DB
            try {
                $newCustomer = Repositories::customer()->saveNewCustomer($data);
            } catch (\Exception $e) {
                Log::critical("User creation in DB failed: " . $e->getMessage());
                return false;
            }

        } catch (UsernameExistsException $e) {
            Log::info("User already exists in Cognito: " . $e->getMessage());


            $resolvedAuth = $this->resolveExistingUserAuth($data);
            $decodedToken = AwsCognitoClient::decodeAccessToken((string) ($resolvedAuth['IdToken'] ?? ''));
            $sub = $decodedToken['sub'] ?? null;

            // Existing users can be promoted to seller, but standard customer signup remains strict.
            if ($isSellerSignup !== true) {
                return false;
            }

            if($decodedToken['custom:seller'] == 1 ) {
                Log::info("User is already a seller in Cognito: " . $e->getMessage());
                return false;
            }

        } catch (\Exception $e) {
            Log::critical($e->getMessage());
            if ($isNewUser === true) {
                AwsCognitoClient::deleteUser($data->auth_token);
            }
            return false;
        }

        $this->updateUserSellerAttributes($data);

        if (!is_string($sub) || $sub === '') {
            Log::error('Missing Cognito sub after signup authentication');
            return false;
        }

        return [
            'sub' => $sub,
            'is_new_user' => $isNewUser,
            'customer' => $newCustomer
        ];
    }

    public function updateUserSellerAttributes(ObjectInterface $data): void
    {
        AwsCognitoClient::setUserEmailVerified($data->get('email'), true);
        if (is_string($data->get('password')) && trim((string) $data->get('password')) !== '') {
            AwsCognitoClient::setUserPassword($data->get('email'), $data->get('password'), true);
        }

        AwsCognitoClient::updateUserAttributes($data->get('email'), [
            'custom:seller' => (int) ($data->get('is_seller')),
            'custom:is_premium' => (int) ($data->get('premium', 0)),
            'name' => $data->get('name'),
        ]);
    }

    private function resolveExistingUserAuth(ObjectInterface $payload): array|bool
    {
        $authToken = $this->normalizeAuthorizationToken((string) ($payload->get('auth_token') ?? ''));
        if ($authToken !== '') {
            $decodedAccessToken = AwsCognitoClient::decodeAccessToken($authToken);
            $sub = $decodedAccessToken['sub'] ?? null;

            if (!is_string($sub) || $sub === '') {
                Log::error('Missing Cognito sub during seller conversion');
                return false;
            }

            $idToken = Cache::get($this->idTokenCacheKey($sub));
            if (!is_string($idToken) || $idToken === '') {
                Log::error('Missing cached id token during seller conversion', [
                    'sub' => $sub,
                ]);
                return false;
            }

            $identity = AwsCognitoClient::decodeAccessToken($idToken);
            $email = (string) ($identity['email'] ?? $decodedAccessToken['username'] ?? '');
            if ($email === '') {
                Log::error('Missing email in Cognito identity during seller conversion', [
                    'sub' => $sub,
                ]);
                return false;
            }

            if ((string) ($payload->get('email') ?? '') !== '' && $payload->get('email') !== $email) {
                Log::error('Seller conversion email mismatch', [
                    'payload_email' => $payload->get('email'),
                    'token_email' => $email,
                ]);
                return false;
            }

            return [
                'AccessToken' => $authToken,
                'RefreshToken' => Cache::get($this->refreshTokenCacheKey($sub)),
                'IdToken' => $idToken,
                'sub' => $sub,
                'identity' => $identity,
            ];
        }

        $existingUserAuth = AwsCognitoClient::setBoolClientSecret()->authenticate(
            (string) $payload->get('email'),
            (string) $payload->get('password')
        );

        if (!empty($existingUserAuth['error'])) {
            Log::error('Existing user authentication failed during seller conversion', [
                'email' => $payload->get('email'),
                'error' => $existingUserAuth['error'],
            ]);
            return false;
        }

        return $existingUserAuth;
    }

    private function normalizeAuthorizationToken(?string $authHeader): string
    {
        if (!is_string($authHeader) || trim($authHeader) === '') {
            return '';
        }

        return str_replace('Bearer ', '', trim($authHeader));
    }


    public function confirmToken(string $token): bool
    {
        $email = $this->tags(['user-signup'])->getFromCache($token);
        if (empty($email) || !is_string($email)) {
            Log::critical("User not found");
            return false;
        }

        Manufacturer::query()
            ->where('email', $email)
            ->update(['active' => 1]);

        $this->tags(['user-signup'])->removeFromCache($token);

        return true;
    }
}
