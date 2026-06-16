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

/** Password reset */
$app->post('/api/password-reset', PS\Webservice\Http\Controller\CmsController::class . ':redirectToPrestashop');
$app->put('/api/password-reset', PS\Webservice\Http\Controller\CmsController::class . ':redirectToPrestashop');
$app->patch('/api/password-reset', PS\Webservice\Http\Controller\CmsController::class . ':redirectToPrestashop');

$app->group('/api', function () use ($app) {

    $app->get('/api/categories', PS\Webservice\Http\Controller\CategoryController::class . ':categoryList')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('categories'));
    $app->get('/api/categories/{id}', PS\Webservice\Http\Controller\CategoryController::class . ':categoryListById')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('categories'));
    $app->get('/api/product-list', PS\Webservice\Http\Controller\ProductController::class . ':productList')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('products'));
    $app->get('/api/product-featured', PS\Webservice\Http\Controller\ProductController::class . ':featuredProducts')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('products'));
    $app->get('/api/product-promotions', PS\Webservice\Http\Controller\ProductController::class . ':featuredPromotions')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('products,promotions'));
    $app->get('/api/products', PS\Webservice\Http\Controller\ProductController::class . ':productByCategory')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('products'));
    $app->get('/api/products/{id}/related', PS\Webservice\Http\Controller\ProductController::class . ':productsRelated')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('products'));
    $app->get('/api/products/{id}', PS\Webservice\Http\Controller\ProductController::class . ':productById')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('product-detail'));
    $app->get('/api/product/{slug}', PS\Webservice\Http\Controller\ProductController::class . ':productDetail')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('product-detail'));

    /** brands list */
    $app->get('/api/manufacturers', PS\Webservice\Http\Controller\BrandController::class . ':brandList')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('manufacturers'));
    $app->get('/api/manufacturers/{id}', PS\Webservice\Http\Controller\BrandController::class . ':brandList')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('manufacturers'));

    /** Customer api */
    $app->post('/api/register', PS\Webservice\Http\Controller\CustomerController::class . ':register');
    $app->post('/api/login', PS\Webservice\Http\Controller\CustomerController::class . ':login');
    $app->post('/api/logout', PS\Webservice\Http\Controller\CustomerController::class . ':logout');
    $app->post('/api/contact', PS\Webservice\Http\Controller\CustomerController::class . ':contact');
    $app->post('/api/customers', PS\Webservice\Http\Controller\CustomerController::class . ':createCustomer');
    $app->get('/api/customers/{customerId}', PS\Webservice\Http\Controller\CustomerController::class . ':getAccount');
    $app->put('/api/customers/{customerId}', PS\Webservice\Http\Controller\CustomerController::class . ':updateAccount');
    $app->get('/api/customers/{customerId}/addresses', PS\Webservice\Http\Controller\CustomerController::class . ':getAddresses');
    $app->put('/api/customers/{customerId}/addresses', PS\Webservice\Http\Controller\CustomerController::class . ':updateAddresses');

    /** Order api */
    $app->get('/api/order/{orderId}', PS\Webservice\Http\Controller\OrderController::class . ':getOrder');
    $app->get('/api/order/history/{customerId}', PS\Webservice\Http\Controller\OrderController::class . ':orderHistory');
    $app->post('/api/order', PS\Webservice\Http\Controller\OrderController::class . ':createOrder');
    $app->post('/api/order/confirm', PS\Webservice\Http\Controller\OrderController::class . ':confirmOrder');

    /** Carriers api */
    $app->get('/api/carriers', PS\Webservice\Http\Controller\CarrierController::class . ':carrierList')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('carriers'));
    $app->get('/api/carriers/available', PS\Webservice\Http\Controller\CarrierController::class . ':availableCarriers')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('carriers'));
    $app->get('/api/carriers/{id}', PS\Webservice\Http\Controller\CarrierController::class . ':getCarrier')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('carriers'));

    /** search */
    $app->get('/api/search', PS\Webservice\Http\Controller\ProductController::class . ':searchProducts')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('products'));

    /** Pet professional services api */
    $app->get('/api/pet-services', PS\Webservice\Http\Controller\PetProfessionalServiceController::class . ':index');
    $app->get('/api/pet-services/search', PS\Webservice\Http\Controller\PetProfessionalServiceController::class . ':search');
    $app->get('/api/pet-services/categories', PS\Webservice\Http\Controller\PetProfessionalServiceController::class . ':categories');
    $app->get('/api/pet-services/categores', PS\Webservice\Http\Controller\PetProfessionalServiceController::class . ':categories');
    $app->get('/api/pet-services/{id}', PS\Webservice\Http\Controller\PetProfessionalServiceController::class . ':show');
    $app->post('/api/pet-services', PS\Webservice\Http\Controller\PetProfessionalServiceController::class . ':save')
        ->addMiddleware(new \PS\Webservice\Http\Middleware\AuthenticationMiddleware());
    $app->put('/api/pet-services/{id}', PS\Webservice\Http\Controller\PetProfessionalServiceController::class . ':update')
        ->addMiddleware(new \PS\Webservice\Http\Middleware\AuthenticationMiddleware());
    $app->delete('/api/pet-services/{id}', PS\Webservice\Http\Controller\PetProfessionalServiceController::class . ':destroy')
        ->addMiddleware(new \PS\Webservice\Http\Middleware\AuthenticationMiddleware());

    /** CMS */
    $app->get('/api/cms', PS\Webservice\Http\Controller\CmsController::class . ':cmsList')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('cmslist'));
    $app->get('/api/cms/{id}', PS\Webservice\Http\Controller\CmsController::class . ':cmsDetail')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('cmsdetails'));

    /** Configuration service API */
    $app->post('/api/config/cart-rules', PS\Webservice\Http\Controller\ConfigController::class . ':makeCartRulesConfig');  

    /** MODULES */
    $app->post('/api/modules/welcome-coupon', PS\Webservice\Http\Controller\PrestashopController::class . ':welcomeCoupon');  


});

$app->post('/api/clear-cache', PS\Webservice\Http\Controller\ConfigController::class . ':clearCache');

/** tutte le url le mandiamo su prestashop */
$app->get('/{routes:.+}', PS\Webservice\Http\Controller\CmsController::class . ':redirectToPrestashop');

$app->group('/api/webhook', function () use ($app) {
    /** Stripe webhook */
    $app->post('/api/webhooks/stripe/checkout', PS\Webservice\Http\Controller\StripeWebhookController::class . ':handleWebhook');
    /** PrestaShop product-saved webhook */
    $app->post('/api/webhooks/prestashop/product-saved', PS\Webservice\Http\Controller\PrestashopProductWebhookController::class . ':handleWebhook');
    /** clear cache webhook */
    $app->post('/api/webhooks/clear-cache', PS\Webservice\Http\Controller\PrestashopProductWebhookController::class . ':clearCache');
})->addMiddleware(new \PS\Webservice\Http\Middleware\AuthenticationWebhookMiddleware());