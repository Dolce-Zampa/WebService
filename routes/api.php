<?php

/**
 *  application apps
 */
// Soluzione deprecata perchè non funziona correttamente con le rotte dinamiche
// $app->add(new \PS\Webservice\Http\Middleware\DecodeIdMiddleware());
// $app->add(new \PS\Webservice\Http\Middleware\EncodeIdMiddleware());

/** CLIENT APIs */
$app->get('/api/health', PS\Webservice\Http\Controller\PrestashopController::class . ':healthCheck');

/** Carts api */
$app->get('/api/cart/product/upselling', PS\Webservice\Http\Controller\CartController::class . ':upsellingCart')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('upselling-cart'));
$app->get('/api/cart/list/{customerId}', PS\Webservice\Http\Controller\CartController::class . ':getCartList')->add(new \PS\Webservice\Http\Middleware\AuthenticationMiddleware());
$app->get('/api/cart/{cartId}', PS\Webservice\Http\Controller\CartController::class . ':getCart');
$app->post('/api/cart', PS\Webservice\Http\Controller\CartController::class . ':createCart');
$app->post('/api/cart/{cartId}', PS\Webservice\Http\Controller\CartController::class . ':updateCart');
$app->delete('/api/cart/{cartId}', PS\Webservice\Http\Controller\CartController::class . ':deleteCart');
$app->get('/api/cart-rules', PS\Webservice\Http\Controller\CartController::class . ':getCartRules');
$app->get('/api/cart-rules/coupon/featured', PS\Webservice\Http\Controller\CartController::class . ':getFeaturedCoupons');
$app->get('/api/cart-rules/coupon/{code}', PS\Webservice\Http\Controller\CartController::class . ':getCouponDetail');
$app->post('/api/cart-rules/coupon/{code}/validate/{cartId}', PS\Webservice\Http\Controller\CartController::class . ':validateCoupon');

/** Password reset */
$app->group('/api', function () use ($app) {

    $app->get('/api/categories', PS\Webservice\Http\Controller\CategoryController::class . ':categoryList')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('category-list'));
    $app->get('/api/categories/{id_category}', PS\Webservice\Http\Controller\CategoryController::class . ':categoryListById')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('categories'));
    $app->get('/api/product-list', PS\Webservice\Http\Controller\ProductController::class . ':productList')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('products'));
    $app->get('/api/product-featured', PS\Webservice\Http\Controller\ProductController::class . ':featuredProducts')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('products'));
    $app->get('/api/product-promotions', PS\Webservice\Http\Controller\ProductController::class . ':featuredPromotions')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('products,promotions'));
    $app->post('/api/product-promotions/{promotionId}/click', PS\Webservice\Http\Controller\ProductController::class . ':recordPromotionClick');
    $app->get('/api/products', PS\Webservice\Http\Controller\ProductController::class . ':productByCategory')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('products'));
    $app->get('/api/products/{id_product}/related', PS\Webservice\Http\Controller\ProductController::class . ':productsRelated')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('products'));
    $app->get('/api/products/{id_product}', PS\Webservice\Http\Controller\ProductController::class . ':productById')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('product-detail'));
    $app->get('/api/product/reviews', PS\Webservice\Http\Controller\ProductController::class . ':getAllProductReviews')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('product-reviews', 60));
    $app->get('/api/product/{slug}', PS\Webservice\Http\Controller\ProductController::class . ':productDetail')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('product-detail'));
    $app->post('/api/product/{id_product}/reviews', PS\Webservice\Http\Controller\ProductController::class . ':addProductReview');
    $app->post('/api/product/customizzation/upload-file', PS\Webservice\Http\Controller\ProductController::class . ':uploadCustomizationFile');
    /** brands list */
    $app->get('/api/manufacturers', PS\Webservice\Http\Controller\BrandController::class . ':brandList')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('manufacturers'));
    $app->get('/api/manufacturers/{id_manufacturer}', PS\Webservice\Http\Controller\BrandController::class . ':brandList')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('manufacturer-details'));
    $app->get('/api/manufacturer/{id_manufacturer}/reviews', PS\Webservice\Http\Controller\BrandController::class . ':getManufacturerReviews')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('manufacturers', 10));

    /** Customer api */
    $app->post('/api/register', PS\Webservice\Http\Controller\CustomerController::class . ':register');
    $app->post('/api/login', PS\Webservice\Http\Controller\CustomerController::class . ':login');
    $app->post('/api/logout', PS\Webservice\Http\Controller\CustomerController::class . ':logout');
    $app->post('/api/contact', PS\Webservice\Http\Controller\CustomerController::class . ':contact');
    $app->post('/api/customers', PS\Webservice\Http\Controller\CustomerController::class . ':createCustomer');
    $app->post('/api/password-reset', PS\Webservice\Http\Controller\CustomerController::class . ':sendResetPassword');
    $app->patch('/api/password-reset', PS\Webservice\Http\Controller\CustomerController::class . ':resetPassword');

    $app->get('/api/customers/{customerId}', PS\Webservice\Http\Controller\CustomerController::class . ':getAccount')->add(new \PS\Webservice\Http\Middleware\AuthenticationMiddleware());
    $app->put('/api/customers/{customerId}', PS\Webservice\Http\Controller\CustomerController::class . ':updateAccount')->add(new \PS\Webservice\Http\Middleware\AuthenticationMiddleware());
    $app->get('/api/customers/{customerId}/addresses', PS\Webservice\Http\Controller\CustomerController::class . ':getAddresses')->add(new \PS\Webservice\Http\Middleware\AuthenticationMiddleware());
    $app->put('/api/customers/{customerId}/addresses', PS\Webservice\Http\Controller\CustomerController::class . ':updateAddresses')->add(new \PS\Webservice\Http\Middleware\AuthenticationMiddleware());

    /** Order api */
    $app->get('/api/order/{orderId}', PS\Webservice\Http\Controller\OrderController::class . ':getOrder')->add(new \PS\Webservice\Http\Middleware\AuthenticationMiddleware());
    $app->get('/api/order/history/{customerId}', PS\Webservice\Http\Controller\OrderController::class . ':orderHistory')->add(new \PS\Webservice\Http\Middleware\AuthenticationMiddleware());
    $app->post('/api/order', PS\Webservice\Http\Controller\OrderController::class . ':createOrder');
    $app->post('/api/order/confirm', PS\Webservice\Http\Controller\OrderController::class . ':confirmOrder');

    /** Carriers api */
    $app->get('/api/carriers', PS\Webservice\Http\Controller\CarrierController::class . ':carrierList')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('carriers'));
    $app->get('/api/carriers/available', PS\Webservice\Http\Controller\CarrierController::class . ':availableCarriers')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('carriers'));
    $app->get('/api/carriers/{id_carrier}', PS\Webservice\Http\Controller\CarrierController::class . ':getCarrier')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('carriers'));

    /** search */
    $app->get('/api/search', PS\Webservice\Http\Controller\ProductController::class . ':searchProducts')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('search'));

    /** Pet professional services api */
    $app->get('/api/pet-services', PS\Webservice\Http\Controller\PetProfessionalServiceController::class . ':index');
    $app->get('/api/pet-services/search', PS\Webservice\Http\Controller\PetProfessionalServiceController::class . ':search');
    $app->get('/api/pet-services/categories', PS\Webservice\Http\Controller\PetProfessionalServiceController::class . ':categories');
    $app->get('/api/pet-services/categores', PS\Webservice\Http\Controller\PetProfessionalServiceController::class . ':categories');
    $app->get('/api/pet-services/{id}', PS\Webservice\Http\Controller\PetProfessionalServiceController::class . ':show');
    $app->post('/api/pet-services', PS\Webservice\Http\Controller\PetProfessionalServiceController::class . ':save');
    $app->put('/api/pet-services/{id}', PS\Webservice\Http\Controller\PetProfessionalServiceController::class . ':update');
    $app->delete('/api/pet-services/{id}', PS\Webservice\Http\Controller\PetProfessionalServiceController::class . ':destroy');

    /** CMS */
    $app->get('/api/cms', PS\Webservice\Http\Controller\CmsController::class . ':cmsList')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('cmslist'));
    $app->get('/api/cms/{id_cms}', PS\Webservice\Http\Controller\CmsController::class . ':cmsDetail')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('cmsdetails'));

    /** Configuration service API */
    $app->post('/api/config/cart-rules', PS\Webservice\Http\Controller\ConfigController::class . ':makeCartRulesConfig');  
    $app->get('/api/config/sitemap', PS\Webservice\Http\Controller\ConfigController::class . ':sitemap');
    $app->get('/api/config/countries', PS\Webservice\Http\Controller\ConfigController::class . ':countries');
    $app->get('/api/config/states', PS\Webservice\Http\Controller\ConfigController::class . ':states');
    $app->get('/api/config/states/{id_country}', PS\Webservice\Http\Controller\ConfigController::class . ':states');

    /** MODULES */
    $app->post('/api/modules/welcome-coupon', PS\Webservice\Http\Controller\PrestashopController::class . ':welcomeCoupon');  

});

$app->post('/api/seller/auth/register', PS\Webservice\Http\Controller\Seller\SellerController::class . ':register');
$app->post('/api/seller/auth/login', PS\Webservice\Http\Controller\Seller\SellerController::class . ':login');


//healtcheck seller
$app->get('/api/seller/health', PS\Webservice\Http\Controller\Seller\SellerController::class . ':healthCheck');
$app->group('/api/seller', function() use ($app) {

    /** Sellet api */
    $app->post('/api/seller/auth/confirm-token', PS\Webservice\Http\Controller\Seller\SellerController::class . ':confirmToken');
    $app->post('/api/seller/auth/refresh', PS\Webservice\Http\Controller\Seller\SellerController::class . ':refresh');
    $app->get('/api/seller/me', PS\Webservice\Http\Controller\Seller\SellerController::class . ':me')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('seller',10));
    $app->patch('/api/seller/me', PS\Webservice\Http\Controller\Seller\SellerController::class . ':updateMe');
    $app->get('/api/seller/dashboard/summary', PS\Webservice\Http\Controller\Seller\SellerController::class . ':dashboardSummary')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('seller', 10));
    $app->get('/api/seller/dashboard/products-metrics', PS\Webservice\Http\Controller\Seller\SellerController::class . ':dashboardProductsMetrics')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('seller', 10));
    $app->post('/api/seller/products', PS\Webservice\Http\Controller\Seller\SellerController::class . ':products');
    $app->get('/api/seller/products/{sellerid}', PS\Webservice\Http\Controller\Seller\SellerController::class . ':sellerProducts')->add(new \PS\Webservice\Http\Middleware\AuthenticationMiddleware());
    $app->get('/api/seller/product/{id_product}', PS\Webservice\Http\Controller\Seller\SellerController::class . ':productDetail')->addMiddleware(new \PS\Webservice\Http\Middleware\CachingMiddleware('seller',5));
    $app->patch('/api/seller/products/{id_product}', PS\Webservice\Http\Controller\Seller\SellerController::class . ':updateProduct');
    $app->delete('/api/seller/products/{id_product}', PS\Webservice\Http\Controller\Seller\SellerController::class . ':deleteProduct');
    $app->put('/api/seller/products/{id_product}/discount', PS\Webservice\Http\Controller\Seller\SellerController::class . ':updateProductDiscount');
    $app->delete('/api/seller/products/{iid_productd}/discount', PS\Webservice\Http\Controller\Seller\SellerController::class . ':deleteProductDiscount');
    $app->get('/api/seller/promotions/packages', PS\Webservice\Http\Controller\Seller\SellerController::class . ':promotionPackages');
    $app->get('/api/seller/promotions/products', PS\Webservice\Http\Controller\Seller\SellerController::class . ':promotableProducts');
    $app->post('/api/seller/promotions', PS\Webservice\Http\Controller\Seller\SellerController::class . ':createPromotion');
    $app->post('/api/seller/promotions/{promotionId}/checkout', PS\Webservice\Http\Controller\Seller\SellerController::class . ':createPromotionCheckoutSession');

})->add(new \PS\Webservice\Http\Middleware\AuthenticationMiddleware());

$app->post('/api/clear-cache', PS\Webservice\Http\Controller\ConfigController::class . ':clearCache');

/** tutte le url le mandiamo su prestashop */
$app->get('/{routes:.+}', PS\Webservice\Http\Controller\CmsController::class . ':redirectToPrestashop');

/** Stripe webhook */
$app->post('/api/webhooks/stripe/checkout', PS\Webservice\Http\Controller\StripeWebhookController::class . ':handleWebhook');

$app->group('/api/webhook', function () use ($app) {
    /** PrestaShop product-saved webhook */
    $app->post('/api/webhooks/prestashop/product-saved', PS\Webservice\Http\Controller\PrestashopProductWebhookController::class . ':handleWebhook');
    /** clear cache webhook */
    $app->post('/api/webhooks/clear-cache', PS\Webservice\Http\Controller\PrestashopProductWebhookController::class . ':clearCache');
})->addMiddleware(new \PS\Webservice\Http\Middleware\AuthenticationWebhookMiddleware());