<?php

namespace PS\Webservice\Service\Auth;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PS\Webservice\Facades\AwsCognitoClient;
use PS\Webservice\Traits\UseCache;
use Psr\Http\Message\ServerRequestInterface as Request;
use PS\Webservice\Domain\Models\User;

class LoginService extends SignUpService
{
    use UseCache;

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

            \Illuminate\Support\Facades\Log::debug('Decoded token: ' . json_encode($decodedToken));

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
        // $user = User::where('email', $user)->first();

        // if(is_null($user)) {
        //     Log::info('User not found in database');
        //     return false;
        // }

        // $user->sub = $sub;
        // $user->save();

        // put refresh token in cache
        $refreshToken = $userAuth['RefreshToken'];
        $idToken = $userAuth['IdToken'];
        $accessToken = $userAuth['AccessToken'];

        $this->setToCache($sub.$accessToken.'refres_htoken', $refreshToken, Carbon::now()->addDays(30)->timestamp);
        $this->setToCache($sub.$accessToken.'id_token', $idToken, Carbon::now()->addDays(30)->timestamp);
        
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
        
        Cache::forget($decodedToken['sub'].'refresh_token');
        Cache::forget($decodedToken['sub'].'id_token');
        Cache::forget($decodedToken['sub'].'user_info');
        
        return response([
            'success' => true,
            'message' => 'User logged out'
        ]);
        
    }
}
