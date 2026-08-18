<?php

$container->set(\PS\Webservice\Service\HttpService::class, function ($c) {
    $webserviceConfig = new \PS\Webservice\Domain\Object\WebserviceConfig(
        domain: env('PS_BASE_URL') . '/api',
        headers: [
            "Output-Format" => "JSON",
            'Host' => 'aidyis-prod-backoffice.dolcezampa.com',
	        'Content-Type' => 'application/json',
	        'Accept' => 'application/json',
	        'X-WS-Key' => env('WEBSERVICE_KEY'),
	        'Authorization' => "Bearer ".env('WEBSERVICE_KEY')
        ]
    );
    $webserviceConfig->addQueryParams(['ws_key' => env('WEBSERVICE_KEY') ]);
    return new \PS\Webservice\Service\HttpService($webserviceConfig);
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
    return new \PS\Webservice\Service\Auth\AuthService(
        $c->get(\PS\Webservice\Service\PS\Mailer::class)
    );
});

// CORREZIONE: da get() a set()
$container->set(\PS\Webservice\Service\PS\Mailer::class, function($c) {
    return new \PS\Webservice\Service\PS\Mailer($c->get(\PS\Webservice\Service\HttpService::class));
});

/** CONTROLLERS */


$container->set(\PS\Webservice\Service\Payments\PaymentService::class, function ($c) {
    $payment = new \PS\Webservice\Service\Payments\PaymentService();
    $payment->setApiKey(env('STRIPE_API_KEY'));
    return $payment;
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

$container->set(\PS\Webservice\Service\MailjetService::class, function ($c) {
	    $webserviceConfig = new \PS\Webservice\Domain\Object\WebserviceConfig(
        domain: env('PS_BASE_URL'),
        headers: [
	        'Content-Type' => 'application/json',
	        'Accept' => 'application/json',
	        'Authorization'=> 'Basic '. base64_encode(env('MAILJET_API_KEY').':'.env('MAILJET_SECRET_KEY'))
        ]
    );
	$httpService = new \PS\Webservice\Service\HttpService($webserviceConfig);
	return new \PS\Webservice\Service\MailjetService($httpService);
});
