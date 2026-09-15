<?php

namespace PS\Webservice\Service;

interface MailerInterface
{
    public function sendSignUpMail(string $email, string $username): void;

    public function sendResetPasswordMail(string $email, string $token): void;

    public function sendResetPasswordConfirmationMail(string $email): void;

    public function sendPremiumSignUpMail(string $email, string $username): void;

    public function sendRecoveryCartExpired(string $email, string $paymentUrl, array $products, string $cartTotal, $firstname = '');

    public function sendReviewRequestMail(string $email, string $firstname, int $idOrder, array $products, string $reviewUrl = ''): void;
}