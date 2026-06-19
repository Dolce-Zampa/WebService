<?php

use PS\Webservice\Repositories\PrestashopRepository;

// Slim Container configuration for dependency injection

$container = new \DI\Container();

$container->set(\PS\Webservice\Service\HttpService::class, function ($c) {
    $webserviceCOnfig = new \PS\Webservice\Domain\Object\WebserviceConfig(
        apiKey: env('PS_API_KEY'),
        domain: env('PS_BASE_URL'),
        headers: [
            "Output-Format" => "JSON"

        ]
    );
    $webserviceCOnfig->authToken(env('WEBSERVICE_KEY'));
    return new \PS\Webservice\Service\HttpService($webserviceCOnfig);
});

$container->set(\PS\Webservice\Service\PS\Product::class, function ($c) use($capsule) {
    $httpService = $c->get(\PS\Webservice\Service\HttpService::class);
    $service = new \PS\Webservice\Service\PS\Product($httpService);
    $service->addRepository(new PrestashopRepository($capsule));
    return $service;
});

$container->set(\PS\Webservice\Service\PS\Image::class, function ($c) {
    $httpService = $c->get(\PS\Webservice\Service\HttpService::class);
    return new \PS\Webservice\Service\PS\Image($httpService);
});

$container->set(\PS\Webservice\Service\PS\Customer::class, function ($c) {
    $httpService = $c->get(\PS\Webservice\Service\HttpService::class);
    return new \PS\Webservice\Service\PS\Customer($httpService);
});

$container->set(\PS\Webservice\Service\PS\Category::class, function ($c) {
    $httpService = $c->get(\PS\Webservice\Service\HttpService::class);
    return new \PS\Webservice\Service\PS\Category($httpService);
});

$container->set(\PS\Webservice\Service\PS\Cart::class, function ($c) {
    $httpService = $c->get(\PS\Webservice\Service\HttpService::class);
    return new \PS\Webservice\Service\PS\Cart($httpService);
});

$container->set(\PS\Webservice\Service\PS\Order::class, function ($c) {
    $httpService = $c->get(\PS\Webservice\Service\HttpService::class);
    return new \PS\Webservice\Service\PS\Order($httpService);
});

$container->set(\PS\Webservice\Service\PS\Brand::class, function ($c) {
    $httpService = $c->get(\PS\Webservice\Service\HttpService::class);
    return new \PS\Webservice\Service\PS\Brand($httpService);
});

$container->set(\PS\Webservice\Service\PS\Carrier::class, function ($c) {
    $httpService = $c->get(\PS\Webservice\Service\HttpService::class);
    return new \PS\Webservice\Service\PS\Carrier($httpService);
});

$container->set(\PS\Webservice\Service\PS\Cms::class, function ($c) {
    $httpService = $c->get(\PS\Webservice\Service\HttpService::class);
    return new \PS\Webservice\Service\PS\Cms($httpService);
});

$container->set(\PS\Webservice\Service\PS\PsModule::class, function ($c) {
    $httpService = $c->get(\PS\Webservice\Service\HttpService::class);
    return new \PS\Webservice\Service\PS\PsModule($httpService);
});

/** CONTROLLERS */
$container->set(\PS\Webservice\Http\Controller\ProductController::class, function ($c) {
    $productService = $c->get(\PS\Webservice\Service\PS\Product::class);
    return new \PS\Webservice\Http\Controller\ProductController($productService);
});

$container->set(\PS\Webservice\Http\Controller\CategoryController::class, function ($c) {
    $categoryService = $c->get(\PS\Webservice\Service\PS\Category::class);
    return new \PS\Webservice\Http\Controller\CategoryController($categoryService);
});

$container->set(\PS\Webservice\Http\Controller\BrandController::class, function ($c) {
    $categoryService = $c->get(\PS\Webservice\Service\PS\Brand::class);
    return new \PS\Webservice\Http\Controller\BrandController($categoryService);
});

$container->set(\PS\Webservice\Http\Controller\CustomerController::class, function ($c) {
    $customerService = $c->get(\PS\Webservice\Service\PS\Customer::class);
    return new \PS\Webservice\Http\Controller\CustomerController($customerService);
});

$container->set(\PS\Webservice\Http\Controller\OrderController::class, function ($c) {
    $orderService = $c->get(\PS\Webservice\Service\PS\Order::class);
    return new \PS\Webservice\Http\Controller\OrderController($orderService);
});


$container->set(\PS\Webservice\Service\Payments\PaymentService::class, function ($c) {
    return new \PS\Webservice\Service\Payments\PaymentService();
});

$container->set(\PS\Webservice\Http\Controller\CarrierController::class, function ($c) {
    $currierService = $c->get(\PS\Webservice\Service\PS\Carrier::class);
    return new \PS\Webservice\Http\Controller\CarrierController($currierService);
});

$container->set(\PS\Webservice\Http\Controller\StripeWebhookController::class, function ($c) {
    $orderService = $c->get(\PS\Webservice\Service\PS\Order::class);
    return new \PS\Webservice\Http\Controller\StripeWebhookController($orderService);
});

$container->set(\PS\Webservice\Http\Controller\CmsController::class, function ($c) {
    $orderService = $c->get(\PS\Webservice\Service\PS\Cms::class);
    return new \PS\Webservice\Http\Controller\CmsController($orderService);
});

$container->set(\PS\Webservice\Http\Controller\PrestashopController::class, function ($c) {
    $orderService = $c->get(\PS\Webservice\Service\PS\PsModule::class);
    return new \PS\Webservice\Http\Controller\PrestashopController($orderService);
});

$container->set(\PS\Webservice\Service\OpenAIService::class, function ($c) {
    return new \PS\Webservice\Service\OpenAIService(
        env('OPENAI_API_KEY', '')
    );
});

$container->set(\PS\Webservice\Service\RedisQueue::class, function ($c) {
    $redis = new \Predis\Client(
        [
            'scheme' => env('CACHE_REDIS_SCHEME', 'tcp'),
            'host'   => env('CACHE_REDIS_HOST', '127.0.0.1'),
            'port'   => (int) env('CACHE_REDIS_PORT', 6379),
        ],
        [
            'parameters' => [
                'password' => env('CACHE_REDIS_PASSWORD', ''),
                // Use a separate DB from the cache (DB 10) to avoid key collisions
                'database' => (int) env('QUEUE_REDIS_DATABASE', 11),
            ],
        ]
    );
    return new \PS\Webservice\Service\RedisQueue($redis);
});

$container->set(\PS\Webservice\Http\Controller\PrestashopProductWebhookController::class, function ($c) {
    $openAIService  = $c->get(\PS\Webservice\Service\OpenAIService::class);
    $productService = $c->get(\PS\Webservice\Service\PS\Product::class);
    $queue          = $c->get(\PS\Webservice\Service\RedisQueue::class);
    return new \PS\Webservice\Http\Controller\PrestashopProductWebhookController($openAIService, $productService, $queue);
});

$container->set(\PS\Webservice\Http\Controller\PetProfessionalServiceController::class, function ($c) {
    return new \PS\Webservice\Http\Controller\PetProfessionalServiceController();
});
