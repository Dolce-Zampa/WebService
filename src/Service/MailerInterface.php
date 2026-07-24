<?php

namespace PS\Webservice\Service;

interface MailerInterface
{
    public function sendSignUpMail(string $email, string $username): void;

    public function sendResetPasswordMail(string $email, string $token): void;

    public function sendResetPasswordConfirmationMail(string $email): void;
}