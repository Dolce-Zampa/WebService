<?php
namespace PS\Webservice\Service\Auth;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Facade;
use PS\Webservice\Domain\Models\PS\Customer;
use PS\Webservice\Domain\Models\User;
use PS\Webservice\Facades\AwsCognitoClient;
use PS\Webservice\Service\MailerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthServiceProvider {

    protected MailerInterface $mailer;

    public function __construct(MailerInterface $mailer)
    {
        $this->mailer = $mailer;
    }

    protected function refreshTokenCacheKey(string $sub): string
    {
        return $sub . 'refresh_token';
    }

    protected function idTokenCacheKey(string $sub): string
    {
        return $sub . 'id_token';
    }

    private function facebookStateCacheKey(string $state): string
    {
        return 'facebook_oauth_state_' . hash('sha256', $state);
    }

    private function cognitoHostedUiDomain(): string
    {
        $domain = trim((string) env('AWS_COGNITO_DOMAIN', ''));
        $domain = preg_replace('#^https?://#i', '', $domain) ?? '';
        $domain = rtrim($domain, '/');

        if ($domain === '' || !preg_match('/^[A-Za-z0-9.-]+$/', $domain)) {
            throw new \RuntimeException('Invalid Cognito Hosted UI domain');
        }

        return $domain;
    }

    /**
     * Builds a one-time Cognito Hosted UI URL for the Facebook provider.
     *
     * The callback URL is configured on the Cognito app client and is also used
     * when exchanging the authorization code. Keeping both values server-side
     * prevents a client from choosing an arbitrary OAuth redirect URL.
     */
    public function facebookAuthorizationUrl(): string
    {
        $redirectUri = trim((string) env('AWS_COGNITO_REDIRECT_URI', ''));

        if ($redirectUri === '') {
            throw new \RuntimeException('Facebook authentication is not configured');
        }

        $domain = $this->cognitoHostedUiDomain();

        $state = bin2hex(random_bytes(32));
        Cache::put($this->facebookStateCacheKey($state), true, Carbon::now()->addMinutes(10));

        return 'https://' . $domain . '/oauth2/authorize?' . http_build_query([
            'client_id' => env('AWS_COGNITO_CLIENT_ID'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'identity_provider' => 'Facebook',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchanges a Facebook Hosted UI authorization code and returns verified
     * Cognito identity claims together with the access token used by the API.
     *
     * @return array{access_token:string,id_token:string,identity:array<string,mixed>}
     */
    public function authenticateFacebook(string $code, string $state): array
    {
        if ($code === '' || $state === '' || Cache::pull($this->facebookStateCacheKey($state)) !== true) {
            throw new \RuntimeException('Invalid or expired Facebook authentication state');
        }

        $tokenResponse = (new \GuzzleHttp\Client([
            'base_uri' => 'https://' . $this->cognitoHostedUiDomain(),
            'timeout' => 10,
        ]))->post('/oauth2/token', [
            'form_params' => [
                'grant_type' => 'authorization_code',
                'client_id' => (string) env('AWS_COGNITO_CLIENT_ID'),
                'client_secret' => (string) env('AWS_COGNITO_CLIENT_SECRET'),
                'code' => $code,
                'redirect_uri' => (string) env('AWS_COGNITO_REDIRECT_URI'),
            ],
        ]);
        $tokens = json_decode((string) $tokenResponse->getBody());
        if (!is_object($tokens)) {
            throw new \RuntimeException('Invalid Cognito token response');
        }

        $accessToken = (string) ($tokens->access_token ?? '');
        $idToken = (string) ($tokens->id_token ?? '');
        if ($accessToken === '' || $idToken === '') {
            throw new \RuntimeException('Cognito did not return the required tokens');
        }

        $identity = AwsCognitoClient::decodeAccessToken($idToken);
        $sub = $identity['sub'] ?? null;
        $email = $identity['email'] ?? null;
        if (!is_string($sub) || $sub === '' || !is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Cognito did not return a valid Facebook identity');
        }

        if (isset($tokens->refresh_token) && is_string($tokens->refresh_token) && $tokens->refresh_token !== '') {
            Cache::put($this->refreshTokenCacheKey($sub), $tokens->refresh_token, Carbon::now()->addDays(30));
        }
        Cache::put($this->idTokenCacheKey($sub), $idToken, Carbon::now()->addDays(30));

        return [
            'access_token' => $accessToken,
            'id_token' => $idToken,
            'identity' => $identity,
        ];
    }

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
        $user = Customer::where('email', Crypt::encrypt($userEmail))->with('workspaces')->first();
        $sub = $content['sub'];
      
        if(!$user) {
            $this->signupFromProvider($content['name'], $userEmail, $sub);
        } else {
            // Update user information sub
            $user->sub = $sub;
            $user->save();
        }

        //retrive user workspaces
        $user = Customer::where('email', Crypt::encrypt($userEmail))->with('workspaces')->first();

        Cache::put($this->refreshTokenCacheKey($sub), $tokens->refresh_token, Carbon::now()->addDays(30));
        Cache::put($this->idTokenCacheKey($sub), $tokens->id_token, Carbon::now()->addDays(30));
            
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
