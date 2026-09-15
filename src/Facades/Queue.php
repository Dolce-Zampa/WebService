<?php

namespace App\Facades;

/**
 * Summary of RedisQueue
 * 
 * @method static void push(string $queue, $payload)
 * @method static mixed pop(string $queue)
 * 
 * @see \PS\Webservice\Service\RedisQueue
 */
class Queue extends \Illuminate\Support\Facades\Facade
{
    protected static function getFacadeAccessor()
    {
        return 'queue-service';
    }
}