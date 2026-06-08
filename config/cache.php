<?php
require_once __DIR__.'/caching/redis.php';

use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;

$cacheDriver = env('CACHE_DRIVER', 'file');
// Configura il file store per il caching
if($cacheDriver === 'file') {
    $fs = new Filesystem();
    $store = new FileStore($fs, env('CACHE_PATH', __DIR__ . '/../storage/cache'));
}

if($cacheDriver === 'redis') {
    $store = new \Illuminate\Cache\RedisStore(
        new RedisCache()
    );
}

// Crea un'istanza del Repository del cache utilizzando il file store
$cache = new Repository($store);