<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PS;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Domain\Entities\ManufactureEntity;
use PS\Webservice\Domain\Enums\TemplateMail;
use PS\Webservice\Domain\Object\PayloadServiceData;
use PS\Webservice\Facades\PaymentService;
use PS\Webservice\Service\MailerInterface;
use PS\Webservice\Traits\UseCache;

class Mailer extends PrestashopService implements PrestashopServiceInterface, MailerInterface {
    use UseCache;

    public function sendSignUpMail($email, $username):void 
    {
        //generate a token for the user and save into a cache
        $token = bin2hex(random_bytes(16));

        $this->tags(['user-signup']);
        $this->setToCache($token, $email, 3600); // Cache for 1 hour

        try {
            $this->httpService->setUrl('/mailer');
            $this->httpService->invoke('POST',
            new PayloadServiceData(
                [
                    'subject' => 'Benvenuto su Dolce & Zampa!',
                    'to_email' => $email,
                    'to_name' => $username,
                    'template_vars' => [
                        'token' => $token,
                    ],
                    'template' => TemplateMail::SIGNUP->value
                ]
            ));
        } catch (\Throwable $e) {
           throw new PrestashopConnectorException($this->httpService, $e);
        }
        
    }

    public function sendSignupSellerMail(string $email, string $username): void
    {
        try {
            $this->httpService->setUrl('/mailer?debug=true');
            $this->httpService->invoke('POST',
                new PayloadServiceData(
                    [
                        'subject' => 'Benvenuto su Dolce & Zampa come artigiano!',
                        'to_email' => $email,
                        'to_name' => $username,
                        'template' => TemplateMail::SIGNUP_SELLER->value,
                    ]
                ));
        } catch (\Throwable $e) {
            throw new PrestashopConnectorException($this->httpService, $e);
        }
    }

    public function sendResetPasswordMail(string $email, string $token): void
    {
        try {
            $this->httpService->setUrl('/mailer?debug=true');
            $this->httpService->invoke('POST',
                new PayloadServiceData(
                    [
                        'subject' => 'Link reset password acocunt',
                        'to_email' => $email,
                        'template' => TemplateMail::RESET_PASSWORD->value,
                        'template_vars' => [
                            'token' => $token,
                            'url' => env('APP_URL') . '/reset-password?token=' . $token
                        ]
                    ]
                ));
        } catch (\Throwable $e) {
            throw new PrestashopConnectorException($this->httpService, $e);
        }
    }

    public function sendResetPasswordConfirmationMail(string $email): void
    {
        try {
            $this->httpService->setUrl('/mailer?debug=true');
            $this->httpService->invoke('POST',
                new PayloadServiceData(
                    [
                        'subject' => 'Password reset successfully',
                        'to_email' => $email,
                        'template' => TemplateMail::PASSWORD_UPDATED->value,
                    ]
                ));
        } catch (\Throwable $e) {
            throw new PrestashopConnectorException($this->httpService, $e);
        }
    }

    public function sendPremiumSignUpMail(string $email, string $username): void
    {
        try {
            $paymentLink = PaymentService::getPaymentUrl(
                env('STRIPE_PREMIUM_PRICE_ID'),
                ManufactureEntity::create([
                    'username' => $username,
                    'email' => $email,
                    'product' => 'premium'
                ], new PrestashopService($this->httpService))
            );
        } catch (\Throwable $e) {
            Log::critical("Failed to generate payment link for premium signup: " . $e->getMessage());
            throw new PrestashopConnectorException($this->httpService, $e);
        }

        try {
            $this->httpService->setUrl('/mailer?debug=true');
            $this->httpService->invoke('POST',
                new PayloadServiceData(
                    [
                        'subject' => 'Benvenuto nel programma Premium di Dolce & Zampa!',
                        'to_email' => $email,
                        'to_name' => $username,
                        'template' => TemplateMail::PREMIUM_SIGNUP->value,
                        'template_vars' => [
                            'name' => $username,
                            'payment_url' => $paymentLink,
                        ]
                    ]
                ));
        } catch (\Throwable $e) {
            throw new PrestashopConnectorException($this->httpService, $e);
        }
    }
   
}
