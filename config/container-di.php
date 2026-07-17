<?php

$container = new \DI\Container();

$container->set(\PS\Webservice\Service\HttpService::class, function ($c) {
    $webserviceConfig = new \PS\Webservice\Domain\Object\WebserviceConfig(
        apiKey: env('PS_API_KEY'),
        domain: env('PS_BASE_URL'),
        headers: [
            "Output-Format" => "JSON"
        ]
    );
    $webserviceConfig->authToken(env('WEBSERVICE_KEY'));
    return new \PS\Webservice\Service\HttpService($webserviceConfig);
});

$container->set(\PS\Webservice\Repositories\PrestashopRepository::class, function($c) use($capsule) {
    return new \PS\Webservice\Repositories\PrestashopRepository($capsule);
});

$container->set(\PS\Webservice\Service\PS\Product::class, function ($c) {
    $httpService = $c->get(\PS\Webservice\Service\HttpService::class);
    $service = new \PS\Webservice\Service\PS\Product($httpService);
    $service->addRepository(
        $c->get(\PS\Webservice\Repositories\PrestashopRepository::class)
    );
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

$container->set(\PS\Webservice\Service\PS\PrestashopService::class, function ($c) {
    $httpService = $c->get(\PS\Webservice\Service\HttpService::class);
    return new \PS\Webservice\Service\PS\PrestashopService($httpService);
});

// CORREZIONE: da get() a set()
$container->set(\PS\Webservice\Service\Auth\AuthService::class, function($c) {
    return new \PS\Webservice\Service\Auth\AuthService();
});

// CORREZIONE: da get() a set()
$container->set(\PS\Webservice\Service\PS\Mailer::class, function($c) {
    return new \PS\Webservice\Service\PS\Mailer($c->get(\PS\Webservice\Service\HttpService::class));
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
    $authService = $c->get(\PS\Webservice\Service\Auth\AuthService::class);
    return new \PS\Webservice\Http\Controller\CustomerController($customerService, $authService);
});

$container->set(\PS\Webservice\Http\Controller\OrderController::class, function ($c) {
    $orderService = $c->get(\PS\Webservice\Service\PS\Order::class);
    return new \PS\Webservice\Http\Controller\OrderController($orderService);
});

$container->set(\PS\Webservice\Http\Controller\Seller\SellerController::class, function ($c) {
    $authService = $c->get(\PS\Webservice\Service\Auth\AuthService::class);
    $prestashopService = $c->get(\PS\Webservice\Service\PS\PrestashopService::class);
    $mailer = $c->get(\PS\Webservice\Service\PS\Mailer::class);
    $repository = $c->get(\PS\Webservice\Repositories\PrestashopRepository::class);
    $product = $c->get(\PS\Webservice\Service\PS\Product::class);
    return new \PS\Webservice\Http\Controller\Seller\SellerController($authService, $prestashopService,$mailer, $repository, $product);
});


$container->set(\PS\Webservice\Service\Payments\PaymentService::class, function ($c) {
    return new \PS\Webservice\Service\Payments\PaymentService();
});

// CORREZIONE: $currierService → $carrierService
$container->set(\PS\Webservice\Http\Controller\CarrierController::class, function ($c) {
    $carrierService = $c->get(\PS\Webservice\Service\PS\Carrier::class);
    return new \PS\Webservice\Http\Controller\CarrierController($carrierService);
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