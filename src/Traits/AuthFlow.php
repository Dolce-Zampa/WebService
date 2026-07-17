<?php
namespace PS\Webservice\Traits;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use MDF\CognitoIntegration\Domain\Model\Token;

trait AuthFlow {

    /**
     * Generates a token for Authentication.
     *
     * @param array $params The parameters for generating the token.
     * @param int $userId The ID of the user.
     * @param string $type The type of token to generate (default: 'signup').
     * @return string The generated token.
     */
    public function generateToken(array $params, $userId, string $type = 'signup'): string
    {
        // save token in cache
        $token = Token::create(array_merge([
            'user_id' => $userId,
            'type' => $type
        ], $params));

        $userData = new \stdClass();
        $userData->email = $params["email"];
        $userData->password = $params['password'];
        $userData->id = $userId;

        $tokenLabel = sha1($token->getToken());
        Cache::put($tokenLabel, $userData, Carbon::now()->addMinutes(10));

        return $tokenLabel;
        
    }
}