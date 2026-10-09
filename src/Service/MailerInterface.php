<?php

namespace PS\Webservice\Service;

use PS\Webservice\Domain\Financial\WeeklySellerFinancialSummary;

interface MailerInterface
{
    public function sendSignUpMail(string $email, string $username): void;

    public function sendResetPasswordMail(string $email, string $token): void;

    public function sendResetPasswordConfirmationMail(string $email): void;

    public function sendPremiumSignUpMail(string $email, string $username): void;

    public function sendRecoveryCartExpired(string $email, string $paymentUrl, array $products, string $cartTotal, $firstname = '');

    public function sendReviewRequestMail(string $email, string $firstname, int $idOrder, array $products, string $reviewUrl = ''): void;

    public function sendWeeklySellerFinancialSummary(string $email, string $sellerName, WeeklySellerFinancialSummary $summary): void;

    /** @param array<int, array{name: string, quantity: int, amount: string}> $items */
    public function sendSellerSaleNotification(string $email, string $sellerName, string $orderReference, string $finalizedAt, array $items, string $amount, string $status): void;
}
