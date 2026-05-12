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

// More functions...
