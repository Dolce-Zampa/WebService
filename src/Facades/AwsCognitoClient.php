<?php

namespace PS\Webservice\Facades;

use Illuminate\Support\Facades\Facade;


/**
 * This class represents a facade for interacting with the AWS Cognito client.
 * It extends the base Facade class.
 * @method updateUserAttributes($username, array $attributes = [])
 * @method setUserPassword($username, $password, $permanent = false)
 * @method setUserEmailVerified($username, $verified = true)
 * @method deleteUser($username)
 * @method \malirobot\AwsCognito\CognitoClient setBoolClientSecret()
 * @method createUser($username, $password, $confirmPassword, array $attributes = [], array $clientMetadata = null, string $groupname = null)
 * @method array decodeAccessToken(string $accessToken)
 * 
 * @see \malirobot\AwsCognito\CognitoClient
 */
class AwsCognitoClient extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'aws-cognito-client';
    }
}
