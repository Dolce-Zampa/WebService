<?php

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
    $repository = $c->get(\PS\Webservice\Repositories\PrestashopRepository::class);
    $mailer =   $c->get(\PS\Webservice\Service\PS\Mailer::class);
    $mailjet = $c->get(\PS\Webservice\Service\MailjetService::class);
    return new \PS\Webservice\Http\Controller\CustomerController($customerService, $authService, $repository, $mailer, $mailjet);
});

$container->set(\PS\Webservice\Http\Controller\OrderController::class, function ($c) {
    $orderService = $c->get(\PS\Webservice\Service\PS\Order::class);
    $payment = $c->get(\PS\Webservice\Service\Payments\PaymentService::class);
    return new \PS\Webservice\Http\Controller\OrderController($orderService,$payment);
});

$container->set(\PS\Webservice\Http\Controller\Seller\SellerController::class, function ($c) {
    $authService = $c->get(\PS\Webservice\Service\Auth\AuthService::class);
    $prestashopService = $c->get(\PS\Webservice\Service\PS\PrestashopService::class);
    $mailer = $c->get(\PS\Webservice\Service\PS\Mailer::class);
    $repository = $c->get(\PS\Webservice\Repositories\ManufacturerRepository::class);
    $product = $c->get(\PS\Webservice\Service\PS\Product::class);
    $mailjet = $c->get(\PS\Webservice\Service\MailjetService::class);
    return new \PS\Webservice\Http\Controller\Seller\SellerController($authService, $prestashopService,$mailer, $repository, $product, $mailjet);
});

// CORREZIONE: $currierService → $carrierService
$container->set(\PS\Webservice\Http\Controller\CarrierController::class, function ($c) {
    $carrierService = $c->get(\PS\Webservice\Service\PS\Carrier::class);
    return new \PS\Webservice\Http\Controller\CarrierController($carrierService);
});

$container->set(\PS\Webservice\Http\Controller\StripeWebhookController::class, function ($c) {
    $orderService = $c->get(\PS\Webservice\Service\PS\Order::class);
    $mailjet = $c->get(\PS\Webservice\Service\MailjetService::class);
    $payment = $c->get(\PS\Webservice\Service\Payments\PaymentService::class);
    $mailer = $c->get(\PS\Webservice\Service\PS\Mailer::class);
    return new \PS\Webservice\Http\Controller\StripeWebhookController($orderService,$mailjet,$payment,$mailer);
});

$container->set(\PS\Webservice\Http\Controller\CmsController::class, function ($c) {
    $orderService = $c->get(\PS\Webservice\Service\PS\Cms::class);
    return new \PS\Webservice\Http\Controller\CmsController($orderService);
});

$container->set(\PS\Webservice\Http\Controller\PrestashopController::class, function ($c) {
    $orderService = $c->get(\PS\Webservice\Service\PS\PsModule::class);
    $mailjet = $c->get(\PS\Webservice\Service\MailjetService::class);
    return new \PS\Webservice\Http\Controller\PrestashopController($orderService,$mailjet);
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