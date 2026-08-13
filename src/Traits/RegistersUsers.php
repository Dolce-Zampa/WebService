<?php

namespace PS\Webservice\Traits;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Domain\Entities\CustomerEntity;
use PS\Webservice\Domain\ObjectInterface;
use PS\Webservice\Facades\AwsCognitoClient;

trait RegistersUsers
{
    /**
     * Handle a registration request for the application.
     *
     * @param CustomerEntity $request
     * @return array<string, mixed>
     * @throws \InvalidArgumentException
     */
    public function createCognitoUser(ObjectInterface $request, ?array $clientMetadata = null, ?string $groupname = null)
    {
        $email = $request->email;
        $username = $email;
        $password = $request->password;

        //Initialize Cognito Attribute array
        $attributes = [];

        //Get the registeration fields
        $userFields = explode(',',env('COGNITO_USER_FIELDS',''));

        //Iterate the fields
        foreach ($userFields as $key => $userField) {
            if ($userField!=null) {
                if ($request->$userField !== null) {
                    $attributes[$key] = $request->$userField;
                } else {
                    Log::error('RegistersUsers:createCognitoUser:missing user field');
                    Log::error("The configured user field {$userField} is not provided in the request.");
                    throw new \InvalidArgumentException("The configured user field {$userField} is not provided in the request.");
                } //End if
            } //End if
        } //Loop ends

        return AwsCognitoClient::createUser($username, $password, $password, [
            'email' => $email,
            'email_verified' => 'true',
        ]);
    }

} 
