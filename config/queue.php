<?php
use Illuminate\Queue\Capsule\Manager as Queue;

$queue = new Queue();

$queue->addConnection([
    'driver' => 'redis',
    'host' => $_ENV['REDIS_HOST'],
    'port' => $_ENV['REDIS_PORT'],
]);

$container->set(
    Queue::class,
    $queue
);