<?php
namespace PS\Webservice\Traits;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

trait AuthFlow {

    /**
     * Generates a token for Authentication.
     *
     * @param array $params The parameters for generating the token.
     * @return string The generated token.
     */
    public function generateToken(array $params): string
    {
        // save token in cache
        $token = generate_token($params);
        return $token;
        
    }
}