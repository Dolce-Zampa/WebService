<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PS;

use PS\Webservice\Domain\Object\PayloadServiceData;
use PS\Webservice\Traits\UseCache;

class Mailer extends PrestashopService implements PrestashopServiceInterface {
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
                    'email' => $email,
                    'username' => $username,
                    'token' => $token
                ]
            ));
        } catch (\Throwable $e) {
           throw new PrestashopConnectorException($this->httpService, $e);
        }
        
    }
   
}
