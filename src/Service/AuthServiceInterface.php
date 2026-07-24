<?php

namespace PS\Webservice\Service;

use Psr\Http\Message\ServerRequestInterface as Request;
use Stringable;

interface AuthServiceInterface
{
    public function authenticateProvider(Request $request, string $providerName);

    public function providerToken(Request $request);

    public function delete(Stringable $uuid);

    public function signUp(Request $request): array|bool;

    public function confirmToken(string $token);

    public function authenticate(Request $request);

    public function logout(Request $request);

    public function check(Request $request);

    public function authUserInfo(Request $request);

    public function resetPassword(Request $request, string $token);

    public function sendVerifyEmail(Request $request);

    public function sendResetPasswordMail(string $request);

    public function userInfoByEmail(string $email);
}