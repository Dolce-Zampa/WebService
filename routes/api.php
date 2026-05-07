<?php
/**
 *  application apps
 */

$app->get('/api/categories', DolzeZampa\WS\Http\Controller\CategoryController::class . ':categoryList')->addMiddleware(new \DolzeZampa\WS\Http\Middleware\CachingMiddleware());
$app->get('/api/product-list', DolzeZampa\WS\Http\Controller\PsProductController::class . ':productList')->addMiddleware(new \DolzeZampa\WS\Http\Middleware\CachingMiddleware());
$app->get('/api/product-featured', DolzeZampa\WS\Http\Controller\PsProductController::class . ':featuredProducts')->addMiddleware(new \DolzeZampa\WS\Http\Middleware\CachingMiddleware());
$app->get('/api/products', DolzeZampa\WS\Http\Controller\PsProductController::class . ':productByCategory')->addMiddleware(new \DolzeZampa\WS\Http\Middleware\CachingMiddleware());
$app->get('/api/products/{id}/related', DolzeZampa\WS\Http\Controller\PsProductController::class . ':productsRelated')->addMiddleware(new \DolzeZampa\WS\Http\Middleware\CachingMiddleware());

$app->get('/api/product/{slug}', DolzeZampa\WS\Http\Controller\PsProductController::class . ':productDetail')->addMiddleware(new \DolzeZampa\WS\Http\Middleware\CachingMiddleware());

/** Carts api */
$app->get('/api/cart/list/{customerId}', DolzeZampa\WS\Http\Controller\CartController::class . ':cartList');
$app->get('/api/cart/{cartId}', DolzeZampa\WS\Http\Controller\CartController::class . ':getCart');
$app->post('/api/cart', DolzeZampa\WS\Http\Controller\CartController::class . ':createCart');
$app->post('/api/cart/{customerId}', DolzeZampa\WS\Http\Controller\CartController::class . ':addToCart');
/** Customer api */
$app->post('/api/register', DolzeZampa\WS\Http\Controller\CustomerController::class . ':register');
$app->post('/api/login', DolzeZampa\WS\Http\Controller\CustomerController::class . ':login');
$app->post('/api/customers', DolzeZampa\WS\Http\Controller\CustomerController::class . ':createCustomer');

/** Order api */
$app->get('/api/order/{orderId}', DolzeZampa\WS\Http\Controller\OrderController::class . ':getOrder');
$app->get('/api/order/history/{customerId}', DolzeZampa\WS\Http\Controller\OrderController::class . ':orderHistory');
$app->post('/api/order', DolzeZampa\WS\Http\Controller\OrderController::class . ':createOrder');
$app->post('/api/order/confirm', DolzeZampa\WS\Http\Controller\OrderController::class . ':confirmOrder');

/** Carriers api */
$app->get('/api/carriers', DolzeZampa\WS\Http\Controller\CarrierController::class . ':carrierList');//->addMiddleware(new \DolzeZampa\WS\Http\Middleware\CachingMiddleware(3600));
$app->get('/api/carriers/available', DolzeZampa\WS\Http\Controller\CarrierController::class . ':availableCarriers');
$app->get('/api/carriers/{id}', DolzeZampa\WS\Http\Controller\CarrierController::class . ':getCarrier')->addMiddleware(new \DolzeZampa\WS\Http\Middleware\CachingMiddleware(3600));

