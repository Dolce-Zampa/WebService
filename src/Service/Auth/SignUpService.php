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

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use malirobot\AwsCognito\Exception\UsernameExistsException;
use PS\Webservice\Domain\Models\Manufacturer;
use PS\Webservice\Domain\Models\ManufacturerDetail;
use PS\Webservice\Domain\Models\ManufacturerLang;
use PS\Webservice\Domain\Models\ManufacturerShop;
use PS\Webservice\Domain\Models\User;
use PS\Webservice\Facades\AwsCognitoClient;
use PS\Webservice\Traits\AuthFlow;
use PS\Webservice\Traits\RegistersUsers;
use PS\Webservice\Traits\UseCache;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ramsey\Uuid\Nonstandard\Uuid;

class SignUpService extends UserService
{
    use RegistersUsers, AuthFlow, UseCache;

    const URL_SIGNUP_CONFIRM = '/app/auth/confirm/';

    /**
     * Handles the sign up functionality.
     * 
     * "{"User":{"Username":"12954434-c091-7097-0301-07a456e327df","Attributes":[{"Name":"email","Value":"marco.defelice890@gmail.com"},{"Name":"email_verified","Value":"true"},{"Name":"sub","Value":"12954434-c091-7097-0301-07a456e327df"}],"UserCreateDate":"2026-07-16T07:38:31+00:00","UserLastModifiedDate":"2026-07-16T07:38:31+00:00","Enabled":true,"UserStatus":"FORCE_CHANGE_PASSWORD"},"@metadata":{"statusCode":200,"effectiveUri":"https:\/\/cognito-idp.eu-west-1.amazonaws.com\/","headers":{"date":"Thu, 16 Jul 2026 07:38:31 GMT","content-type":"application\/x-amz-json-1.1","content-length":"359","connection":"keep-alive","x-amzn-requestid":"69de265d-be04-4bc1-ba5d-94ea099e365a"},"transferStats":{"http":[[]]}}}"
     *
    * @param mixed $data Signup payload (Request, Collection or array).
     * @return array|bool
     */
    public function signUp(mixed $data): array|bool
    {
        $payload = $this->normalizeSignUpData($data);
        $isSellerSignup = (bool) $payload->get('is_seller', false);
        $isNewUser = false;
        $resolvedAuth = null;
        $authToken = $data['auth_token'] ?? null;

        try {
            if(is_null($authToken) || trim((string) $authToken) === '') {
                $cognito = $this->createCognitoUser($payload);
                if($cognito['error'] ?? false) {
                    Log::error("Cognito user creation failed: " . json_encode($cognito));
                    return false;
                }

                $isNewUser = true;
            }
            $this->updateUserSellerAttributes($payload);

            //create user in DB
            try {
                User::create([
                    'email' => $payload->get('email'),
                    'id_lang' => 1,
                    'active' => 1,
                    'firstname' => $payload->get('first_name'),
                    'lastname' => $payload->get('last_name'),
                ]);
            } catch (\Exception $e) {
                Log::critical("User creation in DB failed: " . $e->getMessage());
                return false;
            }

        } catch (UsernameExistsException $e) {
            Log::info("User already exists in Cognito: " . $e->getMessage());

            // Existing users can be promoted to seller, but standard customer signup remains strict.
            if ($isSellerSignup !== true) {
                return false;
            }

            $resolvedAuth = $this->resolveExistingUserAuth($payload);
            if ($resolvedAuth === false) {
                return false;
            }

            $payload = $this->mergeIdentityIntoPayload($payload, $resolvedAuth);

            $this->updateUserSellerAttributes($payload);
            $this->saveManufacturer($payload);

        } catch (\Exception $e) {
            Log::critical($e->getMessage());
            if ($isNewUser === true) {
                AwsCognitoClient::deleteUser((string) $payload->get('email'));
            }
            return false;
        }

        $decodedToken = AwsCognitoClient::decodeAccessToken((string) $authToken);
        $sub = $decodedToken['sub'] ?? null;
        if (!is_string($sub) || $sub === '') {
            Log::error('Missing Cognito sub after signup authentication', [
                'email' => $payload->get('email'),
            ]);
            return false;
        }

        $this->setToCache($this->refreshTokenCacheKey($sub), $resolvedAuth['RefreshToken'] ?? null, Carbon::now()->addDays(30)->diffInSeconds());
        $this->setToCache($this->idTokenCacheKey($sub), $resolvedAuth['IdToken'] ?? null, Carbon::now()->addDays(30)->diffInSeconds());

        return [
            'access_token' => $auth['AccessToken'] ?? null,
            'refresh_token' => $auth['RefreshToken'] ?? null,
            'id_token' => $auth['IdToken'] ?? null,
            'sub' => $sub,
            'is_new_user' => $isNewUser,
        ];
    }

    public function updateUserSellerAttributes(Collection $data): void
    {
        AwsCognitoClient::setUserEmailVerified($data->get('email'), true);
        if (is_string($data->get('password')) && trim((string) $data->get('password')) !== '') {
            AwsCognitoClient::setUserPassword($data->get('email'), $data->get('password'), true);
        }

        AwsCognitoClient::updateUserAttributes($data->get('email'), [
            'custom:seller' => (int) ($data->get('is_seller')),
            'name' => $data->get('name'),
        ]);
    }

    private function normalizeSignUpData(mixed $data): Collection
    {
        if ($data instanceof Request) {
            $payload = is_array($data->getParsedBody()) ? $data->getParsedBody() : [];
            $firstName = trim((string) ($payload['firstname'] ?? $payload['first_name'] ?? ''));
            $lastName = trim((string) ($payload['lastname'] ?? $payload['last_name'] ?? ''));

            $name = trim((string) ($payload['name'] ?? ''));
            if ($name === '') {
                $name = trim($firstName . ' ' . $lastName);
            }

            return collect([
                'name' => $name,
                'email' => (string) ($payload['email'] ?? ''),
                'password' => (string) ($payload['password'] ?? ''),
                'is_seller' => (bool) ($payload['is_seller'] ?? false),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'auth_token' => $this->normalizeAuthorizationToken($data->getHeaderLine('Authorization')),
            ]);
        }

        if ($data instanceof Collection) {
            return $data;
        }

        if (is_array($data)) {
            return collect($data);
        }

        throw new \InvalidArgumentException('Invalid signup payload format', 400);
    }

    private function resolveExistingUserAuth(Collection $payload): array|bool
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

    private function mergeIdentityIntoPayload(Collection $payload, array $auth): Collection
    {
        $identity = is_array($auth['identity'] ?? null) ? $auth['identity'] : [];
        $email = (string) ($identity['email'] ?? ($payload->get('email') ?? ''));
        $firstName = trim((string) ($identity['given_name'] ?? ($payload->get('first_name') ?? '')));
        $lastName = trim((string) ($identity['family_name'] ?? ($payload->get('last_name') ?? '')));
        $name = trim((string) ($identity['name'] ?? ($payload->get('name') ?? '')));

        if ($name === '' && ($firstName !== '' || $lastName !== '')) {
            $name = trim($firstName . ' ' . $lastName);
        }

        return $payload->merge([
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $name,
        ]);
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

    protected function saveManufacturer(Collection $payload): void
    {
        $manufacturer = Manufacturer::updateOrCreate(
            ['email' => $payload->get('email')],
            [
                'name' => $payload->get('name'),
                'uuid' => Uuid::uuid4()->toString(),
                'active' => 0,
                'link_rewrite' => slugify($payload->get('name')),
                'date_add' => Carbon::now(),
                'date_upd' => Carbon::now(),
                'sub' => $payload->get('sub'),
            ]
        );

        ManufacturerDetail::updateOrCreate(
            ['id_manufacturer' => $manufacturer->ufacturer],
            [
                'id_manufacturer' => $manufacturer->id_manufacturer,
                'first_name' => $payload->get('first_name'),
                'last_name' => $payload->get('last_name'),
                'fiscal_code' => $payload->get('fiscal_code', null),
                'vat_number' => $payload->get('vat_number', null),
                'address' => $payload->get('address', null),
                'city' => $payload->get('city', ''),
                'zip_code' => $payload->get('postcode', null),
                'country' => $payload->get('country', null),
                'state' => $payload->get('state', null),
                'phone_number' => $payload->get('phone_number', null),
                'avatar' => $payload->get('avatar', null)
            ]
        );

        ManufacturerShop::updateOrCreate(
            ['id_manufacturer' => $manufacturer->id_manufacturer],
            [
                'id_manufacturer' => $manufacturer->id_manufacturer,
                'id_shop' => 1,
            ]
        );

        ManufacturerLang::updateOrCreate(
            ['id_manufacturer' => $manufacturer->id_manufacturer],
            [
                'id_manufacturer' => $manufacturer->id_manufacturer,
                'id_lang' => 1,
                'description' => $payload->get('description', null),
                'short_description' => $payload->get('short_description', null),
                'meta_title' => $payload->get('meta_title', null),
                'meta_description' => $payload->get('meta_description', null),
                'meta_keywords' => $payload->get('meta_keywords', null),
            ]
        );

    }
}
