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

use Illuminate\Support\Facades\Log;
use malirobot\AwsCognito\Exception\UsernameExistsException;
use PS\Webservice\Domain\Models\Manufacturer;
use PS\Webservice\Facades\AwsCognitoClient;
use PS\Webservice\Traits\AuthFlow;
use PS\Webservice\Traits\RegistersUsers;
use PS\Webservice\Traits\UseCache;
use Psr\Http\Message\ServerRequestInterface as Request;

class SignUpService extends UserService
{
    use RegistersUsers, AuthFlow, UseCache;

    const URL_SIGNUP_CONFIRM = '/app/auth/confirm/';

    /**
     * Handles the sign up functionality.
     * 
     * "{"User":{"Username":"12954434-c091-7097-0301-07a456e327df","Attributes":[{"Name":"email","Value":"marco.defelice890@gmail.com"},{"Name":"email_verified","Value":"true"},{"Name":"sub","Value":"12954434-c091-7097-0301-07a456e327df"}],"UserCreateDate":"2026-07-16T07:38:31+00:00","UserLastModifiedDate":"2026-07-16T07:38:31+00:00","Enabled":true,"UserStatus":"FORCE_CHANGE_PASSWORD"},"@metadata":{"statusCode":200,"effectiveUri":"https:\/\/cognito-idp.eu-west-1.amazonaws.com\/","headers":{"date":"Thu, 16 Jul 2026 07:38:31 GMT","content-type":"application\/x-amz-json-1.1","content-length":"359","connection":"keep-alive","x-amzn-requestid":"69de265d-be04-4bc1-ba5d-94ea099e365a"},"transferStats":{"http":[[]]}}}"
     *
     * @param Request $request The HTTP request object.
     * @return array|bool
     */
    public function signUp(Request $request): array|bool
    {
        $params = $request->getParsedBody();

        //save in cache user password
        $collection = collect([
            'name' => $params["name"],
            'email' => $params["email"],
            'password' => $params["password"],
            'is_seller' => $params["is_seller"] ?? false
        ]);

        $data = $collection->only('name', 'email', 'password','is_seller');

        //check if user already exist
        // if (Manufacturer::where('email', $params["email"])->exists()) {
        //     Log::info("User already exists");
        //     return false;
        // }

        try {
            $cognito = $this->createCognitoUser($data);
            if($cognito['error'] ?? false) {
                Log::error("Cognito user creation failed: " . json_encode($cognito));
                return false;
            }
        } catch (UsernameExistsException $e) {
            Log::info("User already exists in Cognito: " . $e->getMessage());
            //eseguiamo il login per aggiornare le informazioni dell'utente
            $cognito = [];

        } catch (\Exception $e) {
            Log::critical($e->getMessage());
            AwsCognitoClient::deleteUser($params["email"]);
            //Redirect to view
            return false;
        }

        try {
            AwsCognitoClient::setUserEmailVerified($data->get('email'), true);
            AwsCognitoClient::setUserPassword($data->get('email'), $data->get('password'), true);
            AwsCognitoClient::updateUserAttributes($data->get('email'), [
                'custom:seller' => (int) ($data->get('is_seller')),
                'name' => $data->get('name'),
            ]);

        } catch (\Throwable $e) {
            Log::critical($e->getMessage());
            return false;
        }

        return $cognito;
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
