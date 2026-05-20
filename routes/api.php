<?php
/**
 *  application apps
 */


/** CLIENT APIs */

/** Carts api */
$app->get('/api/cart/list/{customerId}', PS\Webservice\Http\Controller\CartController::class . ':cartList');
$app->get('/api/cart/{cartId}', PS\Webservice\Http\Controller\CartController::class . ':getCart');
$app->post('/api/cart', PS\Webservice\Http\Controller\CartController::class . ':createCart');
$app->post('/api/cart/{cartId}', PS\Webservice\Http\Controller\CartController::class . ':updateCart');
$app->delete('/api/cart/{cartId}', PS\Webservice\Http\Controller\CartController::class . ':deleteCart');
$app->get('/api/cart-rules', PS\Webservice\Http\Controller\CartController::class . ':getCartRules');
$app->get('/api/cart-rules/coupon/featured', PS\Webservice\Http\Controller\CartController::class . ':getFeaturedCoupons');
$app->get('/api/cart-rules/coupon/{code}', PS\Webservice\Http\Controller\CartController::class . ':getCouponDetail');
$app->post('/api/cart-rules/coupon/{code}/validate/{cartId}', PS\Webservice\Http\Controller\CartController::class . ':validateCoupon');

/** Stripe webhook */
$app->post('/api/webhooks/stripe/checkout', PS\Webservice\Http\Controller\StripeWebhookController::class . ':handleWebhook');

$app->group('/api', function () use ($app) {

    $app->get('/api/categories', PS\Webservice\Http\Controller\CategoryController::class . ':categoryList');
    $app->get('/api/categories/{id}', PS\Webservice\Http\Controller\CategoryController::class . ':categoryListById');
    $app->get('/api/product-list', PS\Webservice\Http\Controller\ProductController::class . ':productList');
    $app->get('/api/product-featured', PS\Webservice\Http\Controller\ProductController::class . ':featuredProducts');
    $app->get('/api/products', PS\Webservice\Http\Controller\ProductController::class . ':productByCategory');
    $app->get('/api/products/{id}/related', PS\Webservice\Http\Controller\ProductController::class . ':productsRelated');
    $app->get('/api/product/{slug}', PS\Webservice\Http\Controller\ProductController::class . ':productDetail');

    /** brands list */
    $app->get('/api/manufacturers', PS\Webservice\Http\Controller\BrandController::class . ':brandList');
    $app->get('/api/manufacturers/{id}', PS\Webservice\Http\Controller\BrandController::class . ':brandList');

    /** Customer api */
    $app->post('/api/register', PS\Webservice\Http\Controller\CustomerController::class . ':register');
    $app->post('/api/login', PS\Webservice\Http\Controller\CustomerController::class . ':login');
    $app->post('/api/contact', PS\Webservice\Http\Controller\CustomerController::class . ':contact');
    $app->post('/api/customers', PS\Webservice\Http\Controller\CustomerController::class . ':createCustomer');

    /** Order api */
    $app->get('/api/order/{orderId}', PS\Webservice\Http\Controller\OrderController::class . ':getOrder');
    $app->get('/api/order/history/{customerId}', PS\Webservice\Http\Controller\OrderController::class . ':orderHistory');
    $app->post('/api/order', PS\Webservice\Http\Controller\OrderController::class . ':createOrder');
    $app->post('/api/order/confirm', PS\Webservice\Http\Controller\OrderController::class . ':confirmOrder');

    /** Carriers api */
    $app->get('/api/carriers', PS\Webservice\Http\Controller\CarrierController::class . ':carrierList');
    $app->get('/api/carriers/available', PS\Webservice\Http\Controller\CarrierController::class . ':availableCarriers');
    $app->get('/api/carriers/{id}', PS\Webservice\Http\Controller\CarrierController::class . ':getCarrier');

    /** search */
    $app->get('/api/search', PS\Webservice\Http\Controller\ProductController::class . ':searchProducts');

    /** CMS */
    $app->get('/api/cms', PS\Webservice\Http\Controller\CmsController::class . ':cmsList');
    $app->get('/api/cms/{id}', PS\Webservice\Http\Controller\CmsController::class . ':cmsDetail');

    /** Configuration service API */
    $app->post('/api/config/cart-rules', PS\Webservice\Http\Controller\ConfigController::class . ':makeCartRulesConfig');    

})
->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware())
->addMiddleware(new \PS\Webservice\Http\Middleware\AuthenticationMiddleware());

$app->post('/api/clear-cache', PS\Webservice\Http\Controller\ConfigController::class . ':clearCache');

/** tutte le url le mandiamo su prestashop */
$app->get('/{routes:.+}', PS\Webservice\Http\Controller\CmsController::class . ':redirectToPrestashop');
