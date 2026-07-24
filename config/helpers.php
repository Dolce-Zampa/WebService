<?php

use PS\Webservice\Domain\ObjectInterface;
/**
 * This file is a helper file that contains various functions.
 */

if(!function_exists('config')) {
    function confing(string $key, string $value): string {
        return $_ENV[$key] ?? $value;
    }
}

if(!function_exists('response')) {
    function response(array|ObjectInterface $dataResponse, int $statusCode = 200, array $headers=[]): \Psr\Http\Message\ResponseInterface {
        $response = new \Slim\Psr7\Response();

        if($dataResponse instanceof ObjectInterface) {
            $dataResponse = $dataResponse->toArray();
        }

        $dataResponse = [
            "success" => $statusCode >= 200 && $statusCode < 300,
            "data" => $dataResponse
        ];

        
        $response->getBody()->write(
            json_encode($dataResponse)
        );

        foreach ($headers as $key => $value) {
            $response = $response->withHeader($key, $value);
        }
        
        return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
    }

    if(!function_exists('storage_path')) {
        function storage_path(string $path = ''): string {
            return __DIR__ . '/../storage/' . ltrim($path, '/');
        }
    }
}

if(!function_exists('slugify')) {
    function slugify(string $text): string {
        // replace non letter or digits by -
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);

        // transliterate
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);

        // remove unwanted characters
        $text = preg_replace('~[^-\w]+~', '', $text);

        // trim
        $text = trim($text, '-');

        // remove duplicate -
        $text = preg_replace('~-+~', '-', $text);

        // lowercase
        $text = strtolower($text);

        if (empty($text)) {
            return 'n-a';
        }

        return $text;
    }
}

if(!function_exists('generate_token')) {
    function generate_token(array $params) {
        //convert param to string
        $paramsString = json_encode($params);
        //generate token
        return hash('sha256', $paramsString . time());
    }
}

// More functions...
