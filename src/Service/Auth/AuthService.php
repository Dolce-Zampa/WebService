<?php

namespace PS\Webservice\Service\Auth;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use League\Container\Exception\NotFoundException;
use PS\Webservice\Domain\Models\User;
use PS\Webservice\Exceptions\AuthException;
use PS\Webservice\Facades\AwsCognitoClient;
use PS\Webservice\Service\AuthServiceInterface;
use PS\Webservice\Traits\AuthFlow;
use PS\Webservice\Traits\UseCache;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthService extends LoginService implements AuthServiceInterface
{
    use AuthFlow, UseCache;

    public function check(Request $request): string
    {
        $authToken = $request->getHeader('Authorization')
            ? $request->getHeader('Authorization')[0]
            : null;

        if (!$authToken) {
            throw new AuthException('Missing Authorization header', 401);
        }
        $authToken = str_replace('Bearer ', '', $authToken);
        $decodedToken = AwsCognitoClient::decodeAccessToken($authToken);

        // Check if the token has expired
        if (isset($decodedToken['exp']) && $decodedToken['exp'] < time()) {
            try {
                $refresh_token = Cache::get($this->refreshTokenCacheKey($decodedToken['sub']));
                $tokens = AwsCognitoClient::refreshAuthentication($decodedToken['username'], $refresh_token);
                $authToken = $tokens['AccessToken'];
            } catch (\Throwable $e) {
                throw new AuthException('Token has expired', 401);
            }
        }

        return $authToken;
    }

    public function authUserInfo(Request $request): array
    {
        $authToken = $request->getHeader('Authorization')
            ? $request->getHeader('Authorization')[0]
            : null;

        if (!$authToken) {
            throw new AuthException('Missing Authorization header', 401);
        }

        $authToken = str_replace('Bearer ', '', $authToken);
        $decodedToken = AwsCognitoClient::decodeAccessToken($authToken);

        Log::debug('Decoded token: ' . json_encode($decodedToken));

        $idToken = Cache::get($this->idTokenCacheKey($decodedToken['sub']));
        if (empty($idToken)) {
            throw new AuthException('Invalid id token token', 401);
        }
        $decodedIdToken = AwsCognitoClient::decodeAccessToken($idToken);

        $user = User::where("email", Crypt::encrypt($decodedIdToken['email']))->first();
        $userId = $user->id;

        if (is_null($userId)) {
            throw new NotFoundException("User not found", 404);
        }


        $result = $user->toArray();
        // save in cache
        Cache::put($decodedToken['sub'] . 'user_info', $result, Carbon::now()->addDays(1));

        return $result;
    }

    /**
     * Resets the password for a user.
     *
     * @param Request $request The HTTP request object.
     * @param array $args The route parameters.
     * @return bool True if the password reset was successful, false otherwise.
     */
    public function resetPasswordWithToken(string $password, string $token): bool
    {

        if ($this->existsInCache($token) === false) {
            Log::warning('Token has expired or is invalid: ' . $token);
            return false;
        }

        $email = $this->getFromCache($token);
        try {
            AwsCognitoClient::setUserPassword($email, $password, true);
        } catch (\Throwable $e) {
            Log::error('Cognito reset password failed: ' . $e->getMessage());
            return false;
        }

        $this->mailer->sendResetPasswordConfirmationMail($email);

        return true;
    }

    public function resetPassword($user, $password): bool
    {
        AwsCognitoClient::setUserPassword($user, $password, true);
        return true;
    }

    /**
     * Sends a verification email.
     *
     * @param Request $request The HTTP request object.
     * @param Response $response The HTTP response object.
     * @param array $args The route arguments.
     * @return Response The updated HTTP response object.
     */
    public function sendVerifyEmail(Request $request)
    {
        $email = $request->getParsedBody()['email'];
        $user = User::where('email', Crypt::encrypt($email))->first();
        if ($user) {
            $token = $this->generateToken(['email' => $email, 'password' => '',  $user->id]);
            $this->setToCache($token, $email, 3600); // Cache for 1 hour

            $mail = new \MDF\CognitoIntegration\Service\MailService();
            $mail->send_signUpMail($email, $user->name, $token);
        }

        return response([
            'message' => 'Email sent'
        ], 200);
    }

    /**
     * Sends a reset password email.
     *
     * @param string $email
     * @return Response The updated HTTP response object.
     */
    public function sendResetPasswordMail(string $email)
    {

        $token = $this->generateToken(['email' => $email, 'password' => '']);
        $this->setToCache($token, $email, 3600); // Cache for 1 hour

        $this->mailer->sendResetPasswordMail($email, $token);

        return response([
            'message' => 'Email sent'
        ], 200);
    }

    /**
     * Retrieves user information by email.
     *
     * @param string $email The route parameters.
     * @return Response The HTTP response object.
     */
    public function userInfoByEmail(string $email)
    {
        $user = User::where('email', Crypt::encrypt($email))->first();
        if (!$user) {
            throw new AuthException('User not found', 404);
        }

        return response(
            $user->toArray(),
            200
        );
    }
}
