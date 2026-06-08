<?php
// Parameters passed using a named array:

class RedisCache implements \Illuminate\Contracts\Redis\Factory
{

    public function __construct()
    {
        if(!class_exists('Predis\Client')) {
            throw new \RuntimeException('Predis\Client is not installed. Please install it via composer.');
        }
    }
    
    public function connection($name = null)
    {
        $options = [
            'parameters' => [
                'password' => env('CACHE_REDIS_PASSWORD', ''),
                'database' => 10,
            ],
        ];

        return new \Predis\Client([
            'scheme' => env('CACHE_REDIS_SCHEME', 'tcp'),
            'host'   => env('CACHE_REDIS_HOST', '127.0.0.1'),
            'port'   => env('CACHE_REDIS_PORT', 6379),
        ], $options);
    }
}