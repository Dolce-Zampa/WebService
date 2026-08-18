<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PS;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Domain\Entities\ManufactureEntity;
use PS\Webservice\Domain\Enums\TemplateMail;
use PS\Webservice\Domain\Object\OrderSession;
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
            $this->httpService->setUrl('/mailer?debug=true');
            $this->httpService->invoke('POST',
            new PayloadServiceData(
                [
                    'subject' => 'Benvenuto su Dolce & Zampa!',
                    'to_email' => $email,
                    'to_name' => $username,
                    'template_vars' => [
                        'email' => $email,
                        'token' => $token,
                        'firstname' => $username,
                        'lastname' => '',
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
            $orderSession = OrderSession::create([
                'line_items' => [
                    ['price' => env('STRIPE_PREMIUM_PRICE_ID'), 'quantity' => 1],
                ]
            ], $this);
            $paymentLink = PaymentService::getPaymentUrl(
                $orderSession,
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



    public function sendRecoveryCartExpired(string $email, string $paymentUrl, array $products, string $cartTotal, $firstname = '')
    {
         try {
            $this->httpService->setUrl('/mailer?debug=true');
            $this->httpService->invoke('POST',
                new PayloadServiceData(
                    [
                        'subject' => 'Sei ancora in tempo per il tuo ordine!',
                        'to_email' => $email,
                        'template' => TemplateMail::ABBANDONED_CART->value,
                        'template_vars' => [
                            'products_list' => $this->recoveryCartHtml($products),
                            'cart_total' => $cartTotal,
                            'recovery_url' => $paymentUrl,
                            'firstname' => $firstname,
                        ]
                    ]
                ));
        } catch (\Throwable $e) {
            throw new PrestashopConnectorException($this->httpService, $e);
        }
    }

    private function recoveryCartHtml(array $products): string
    {
        $productsHtml = '';

        foreach ($products as $product) {

            $productsHtml .= '
                <table width="100%" cellpadding="0" cellspacing="0" border="0"
                    style="border-bottom:1px solid #E8DED0; padding:15px 0;">
                    <tr>

                        <td width="90" valign="middle">
                            <img src="' . htmlspecialchars($product['photo']) . '"
                                width="75"
                                height="75"
                                style="display:block;
                                        width:75px;
                                        height:75px;
                                        object-fit:cover;
                                        border-radius:10px;
                                        border:1px solid #E5D8C8;"
                                alt="' . htmlspecialchars($product['name']) . '">
                        </td>

                        <td valign="middle"
                            style="padding-left:15px;
                                font-family:Arial,Helvetica,sans-serif;">

                            <div style="
                                font-size:15px;
                                line-height:20px;
                                font-weight:bold;
                                color:#4F463B;">
                                
                                ' . htmlspecialchars($product['name']) . '

                            </div>

                        </td>

                        <td width="100"
                            valign="middle"
                            align="right"
                            style="
                                font-family:Arial,Helvetica,sans-serif;
                                font-size:15px;
                                font-weight:bold;
                                color:#A07F55;">

                            €' . number_format((float)$product['price'], 2, ',', '.') . '

                        </td>

                    </tr>
                </table>';
            }

            return $productsHtml;
    }
   
}
