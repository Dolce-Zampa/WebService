<?php
namespace PS\Webservice\Service\Auth;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ServiceProvider {

    /**
     * Authenticates the provider.
     *
     * @param Request $request The request object.
     * @param Response $response The response object.
     * @param array $args The arguments passed to the method.
     * @return Response The response object.
     */
    public function authenticateProvider(Request $request, string $providerName)
    {
        $queryParams = $request->getQueryParams();

        try {

            $authCognito = Facade::getFacadeApplication()["aws-cognito-client"];
            if($queryParams['device'] === 'android' || $queryParams['device'] === 'ios') {
                $authCognito = $authCognito->setAppRedirectUri(env('AWS_COGNITO_REDIRECT_DEEPLINK'));
            }

            $provider = $authCognito->provider();
            $uri = $provider->$providerName(env('COGNITO_GOOGLE_AUTH_URL'));

        } catch (\Throwable $e) {
            Log::error($e->getMessage());
            return response([
                'success' => false,
                'message' => "Provider not found"
            ], 400);
        }

        return response([
            'success' => true,
            'uri' => $uri
        ]);
    }

    /**
     * Handles the provider token request.
     *
     * @param Request $request The HTTP request object.
     * @param Response $response The HTTP response object.
     * @param array $args The route parameters.
     * @return Response The HTTP response object.
     */
    public function providerToken(Request $request)
    {
        $queryParams = $request->getQueryParams();
        $isMobile = false;
        if($queryParams['device'] === 'android' || $queryParams['device'] === 'ios') {
            $isMobile = true;
        }

        if(!isset($request->getQueryParams()['code'])) {
            return response([
                'success' => false,
                'message' => 'Missing code'
            ], 400);
        }

        try {
            $authResponse = $this->authenticate($request->getQueryParams()['code'], $isMobile);

        } catch (\Throwable $e) {

            Log::critical($e->getMessage());

            return response([
                'success' => false,
                'message' => "Authentication failed"
            ], 401);
        }

        return response([
            'success' => true,
            'message' => 'User authenticated',
            'token' => $authResponse['token'],
            'refresh_token' => $authResponse['refresh_token'],
            'id_token' => $authResponse['id_token'],
            'workspaces' => $authResponse['workspaces']
        ]);
    }

    /**
     * Authenticates the provided code.
     *
     * @param string $code The code to authenticate.
     * @return array The Authentication result and workspace result.
     */
    private function authenticate(string $code, bool $isMobile = false): array
    {
        if($isMobile) {
            $tokens = $this->authenticateFromMobile($code);
        } else {
            $tokens = $this->authenticateFromWeb($code);
        }

        // Decode ID Token
        $content = AwsCognitoClient::decodeAccessToken($tokens->id_token);
        $userEmail = $content['email'];
        $user = User::where('email', Crypt::encrypt($userEmail))->with('workspaces')->first();
        $sub = $content['sub'];
      
        if(!$user) {
            $this->signupFromProvider($content['name'], $userEmail, $sub);
        } else {
            // Update user information sub
            $user->sub = $sub;
            $user->save();
        }

        //retrive user workspaces
        $user = User::where('email', Crypt::encrypt($userEmail))->with('workspaces')->first();

        Cache::put($sub.'refresh_token', $tokens->refresh_token, Carbon::now()->addDays(30));
        Cache::put($sub.'id_token', $tokens->id_token, Carbon::now()->addDays(30));
            
        return [
            'token' => $tokens->access_token,
            'refresh_token' => $tokens->refresh_token,
            'id_token' => $tokens->id_token,
            'workspaces' => $user->workspaces
        ];
    }

    /**
     * Sign up a user from a provider.
     *
     * @param string $userName The username of the user.
     * @param string $userEmail The email address of the user.
     * @param string $sub The unique identifier of the user from the provider.
     * @return void
     */
    private function signupFromProvider(string $userName, string $userEmail, string $sub): void
    {

        $user = new User();
        $user->email = $userEmail;
        $user->name = $userName;
        $user->uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $user->password = rand(100000, 999999);
        $user->email_verified_at = date('Y-m-d H:i:s');
        $user->sub = $sub;
        $user->save();

    }

    /**
     * Authenticates a user using a web form.
     *
     * @param string $code The authentication code provided by the user.
     * @param string $providerName The name of the authentication provider.
     */
    private function authenticateFromWeb(string $code)
    {
        $tokens = AwsCognitoClient::authenticateProvider($code, env('AWS_COGNITO_REDIRECT_URI'));
        return $tokens;
    }

    /**
     * Authenticates a user from a mobile device using a provided code and provider name.
     *
     * @param string $code The authentication code provided by the mobile device.
     * @param string $providerName The name of the authentication provider.
     */
    private function authenticateFromMobile(string $code)
    {
        $tokens = AwsCognitoClient::authenticateProvider($code, env('AWS_COGNITO_REDIRECT_DEEPLINK'));
        return $tokens;
    }
}
