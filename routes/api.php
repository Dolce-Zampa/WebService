<?php
/**
 *  application apps
 */

$app->get('/api/categories', PS\Webservice\Http\Controller\CategoryController::class . ':categoryList')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware());
$app->get('/api/product-list', PS\Webservice\Http\Controller\ProductController::class . ':productList')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware());
$app->get('/api/product-featured', PS\Webservice\Http\Controller\ProductController::class . ':featuredProducts')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware());
$app->get('/api/products', PS\Webservice\Http\Controller\ProductController::class . ':productByCategory')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware());
$app->get('/api/products/{id}/related', PS\Webservice\Http\Controller\ProductController::class . ':productsRelated')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware());
$app->get('/api/product/{slug}', PS\Webservice\Http\Controller\ProductController::class . ':productDetail')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware());

/** Carts api */
$app->get('/api/cart/list/{customerId}', PS\Webservice\Http\Controller\CartController::class . ':cartList');
$app->get('/api/cart/{cartId}', PS\Webservice\Http\Controller\CartController::class . ':getCart');
$app->post('/api/cart', PS\Webservice\Http\Controller\CartController::class . ':createCart');
$app->post('/api/cart/{customerId}', PS\Webservice\Http\Controller\CartController::class . ':addToCart');
$app->get('/api/coupons/featured', PS\Webservice\Http\Controller\CartController::class . ':getFeaturedCoupons');
$app->get('/api/coupons/{code}', PS\Webservice\Http\Controller\CartController::class . ':getCouponDetail');
$app->get('/api/coupons/{code}/validate/{cartId}', PS\Webservice\Http\Controller\CartController::class . ':validateCoupon');
$app->get('/api/coupon/{code}', PS\Webservice\Http\Controller\CartController::class . ':getCouponDetail');
$app->get('/api/coupon/{code}/validate/{cartId}', PS\Webservice\Http\Controller\CartController::class . ':validateCoupon');
/** Customer api */
$app->post('/api/register', PS\Webservice\Http\Controller\CustomerController::class . ':register');
$app->post('/api/login', PS\Webservice\Http\Controller\CustomerController::class . ':login');
$app->post('/api/customers', PS\Webservice\Http\Controller\CustomerController::class . ':createCustomer');

/** Order api */
$app->get('/api/order/{orderId}', PS\Webservice\Http\Controller\OrderController::class . ':getOrder');
$app->get('/api/order/history/{customerId}', PS\Webservice\Http\Controller\OrderController::class . ':orderHistory');
$app->post('/api/order', PS\Webservice\Http\Controller\OrderController::class . ':createOrder');
$app->post('/api/order/confirm', PS\Webservice\Http\Controller\OrderController::class . ':confirmOrder');

/** Stripe webhook */
$app->post('/api/webhooks/stripe', PS\Webservice\Http\Controller\StripeWebhookController::class . ':handleWebhook');

/** Carriers api */
$app->get('/api/carriers', PS\Webservice\Http\Controller\CarrierController::class . ':carrierList');//->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware(3600));
$app->get('/api/carriers/available', PS\Webservice\Http\Controller\CarrierController::class . ':availableCarriers');
$app->get('/api/carriers/{id}', PS\Webservice\Http\Controller\CarrierController::class . ':getCarrier')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware(3600));
