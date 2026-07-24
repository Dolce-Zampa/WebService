<?php

namespace PS\Webservice\Service\Auth;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PS\Webservice\Domain\Models\User;
use PS\Webservice\Exceptions\AuthException;
use PS\Webservice\Facades\AwsCognitoClient;
use Psr\Http\Message\ServerRequestInterface as Request;

class LoginService extends SignUpService
{
    public function authenticate(Request $request): bool|array
    {
        $user = $request->getParsedBody()['email'];
        $password = $request->getParsedBody()['password'];

        try {
            $userAuth = AwsCognitoClient::setBoolClientSecret()->authenticate($user, $password);

            Log::debug('User authentication attempt', [
                'user' => $user,
                'auth_result' => $userAuth
            ]);

            if($userAuth['error'] ?? false) {
                Log::error('Authentication error: ' . $userAuth['error'], [
                    'user' => $user,
                    'error' => $userAuth['error']
                ]);
                return false;
            }

            //check if is challenge request
            if($userAuth['challenge-request'] ?? false) {
                Log::info('Challenge request received', [
                    'user' => $user,
                    'challenge_name' => $userAuth['challenge-name'],
                    'challenge_parameters' => $userAuth['challenge-parameters'],
                    'session' => $userAuth['session']
                ]);
                return $userAuth['challenge-name'];
            }

            // decode auth token
            $decodedToken = AwsCognitoClient::decodeAccessToken($userAuth['AccessToken']);
            $sub = $decodedToken['sub'];

            Log::debug('Decoded token: ' . json_encode($decodedToken));

            if (!empty($userAuth['error'])) {
                Log::error('Authentication error: ' . $userAuth['error'], [
                    'user' => $user,
                    'error' => $userAuth['error']
                ]);
                return false;
            }
        } catch (\Throwable $e) {
            Log::critical('Authentication error: ' . $e->getMessage(), [
                'user' => $user,
                'exception' => $e
            ]);
            return false;
        }
        $user = User::where('email', $user)->first();

        if(is_null($user)) {
            Log::info('User not found in database');
            return false;
        }

        $user->sub = $sub;
        $user->save();

        // put refresh token in cache
        $refreshToken = $userAuth['RefreshToken'];
        $idToken = $userAuth['IdToken'];
        $accessToken = $userAuth['AccessToken'];

        Cache::put($this->refreshTokenCacheKey($sub), $refreshToken, Carbon::now()->addDays(30));
        Cache::put($this->idTokenCacheKey($sub), $idToken, Carbon::now()->addDays(30));
        
        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'id_token' => $idToken,
            'sub' => $sub
        ];

    }

    public function logout(Request $request)
    {
        $authToken = $request->getHeader('Authorization')
            ? $request->getHeader('Authorization')[0]
            : null;
        
        if(!$authToken) {
            throw new AuthException('Missing Authorization header', 401);
        }
        
        $authToken = str_replace('Bearer ', '', $authToken);
        $decodedToken = AwsCognitoClient::decodeAccessToken($authToken);
        
        Cache::forget($this->refreshTokenCacheKey($decodedToken['sub']));
        Cache::forget($this->idTokenCacheKey($decodedToken['sub']));
        Cache::forget($decodedToken['sub'] . 'user_info');
        
        return response([
            'success' => true,
            'message' => 'User logged out'
        ]);
        
    }
}
