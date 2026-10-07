<?php

/**
 * Route table: path → controller method. Names (last argument) are used to
 * build URLs: path('product.show', {id: 3}) in views, Router::url() in PHP.
 *
 * ->tracked()     counts the page in Admin → Analytics
 * ->admin()       requires an admin login
 * ->withoutCsrf() only for callers that can't send a form token (webhooks, cart API)
 */

declare(strict_types=1);

use App\Controller\Admin;
use App\Controller\Api\CartApiController;
use App\Controller\Payment;
use App\Controller\Shop;
use App\Http\Route;
use App\Http\RouteCollection;

return static function (RouteCollection $r): void {
    // ---- storefront ------------------------------------------------------
    $r->get('/', [Shop\HomeController::class, 'index'], 'home')->tracked();
    $r->get('/product/{id:\d+}', [Shop\ProductController::class, 'show'], 'product.show')->tracked();
    $r->get('/cart', [Shop\CartController::class, 'show'], 'cart')->tracked();
    $r->get('/checkout', [Shop\CheckoutController::class, 'show'], 'checkout')->tracked();
    $r->post('/checkout', [Shop\CheckoutController::class, 'submit'], 'checkout.submit');
    $r->get('/order/confirmation', [Shop\OrderController::class, 'confirmation'], 'order.confirmation')->tracked();
    $r->get('/about', [Shop\PageController::class, 'about'], 'page.about')->tracked();
    $r->get('/info', [Shop\PageController::class, 'info'], 'page.info')->tracked();
    $r->get('/support', [Shop\PageController::class, 'support'], 'page.support')->tracked();
    $r->get('/terms', [Shop\PageController::class, 'terms'], 'page.terms')->tracked();

    // Cart API used by assets/js/app.js. No form token: the session cookie is
    // SameSite=Lax, so other sites can't make the browser POST here with it.
    $r->get('/api/cart', [CartApiController::class, 'show'], 'api.cart');
    $r->post('/api/cart', [CartApiController::class, 'update'], 'api.cart.update')->withoutCsrf();

    // ---- payment providers (signed callbacks, never redirected) -----------
    // The *.php paths are the ones registered at the providers before the
    // move to clean URLs; both forms keep working.
    $r->group('/payment', static function (RouteCollection $r): void {
        $r->post('/oxapay', [Payment\OxaPayWebhookController::class, 'handle'], 'payment.oxapay');
        $r->post('/callback.php', [Payment\OxaPayWebhookController::class, 'handle']);
        $r->post('/stripe', [Payment\StripeWebhookController::class, 'handle'], 'payment.stripe');
        $r->post('/stripe-webhook.php', [Payment\StripeWebhookController::class, 'handle']);
        $r->post('/revolut', [Payment\RevolutWebhookController::class, 'handle'], 'payment.revolut');
        $r->post('/revolut-webhook.php', [Payment\RevolutWebhookController::class, 'handle']);
        $r->get('/paypal/return', [Payment\PayPalReturnController::class, 'handle'], 'payment.paypal.return');
        $r->get('/paypal-return.php', [Payment\PayPalReturnController::class, 'handle']);
    }, static fn (Route $route) => $route->withoutCsrf());

    // ---- admin -------------------------------------------------------------
    $r->get('/admin/login', [Admin\AuthController::class, 'showLogin'], 'admin.login');
    $r->post('/admin/login', [Admin\AuthController::class, 'login'], 'admin.login.submit');
    $r->get('/admin/logout', [Admin\AuthController::class, 'logout'], 'admin.logout');

    $r->group('/admin', static function (RouteCollection $r): void {
        $r->get('/products', [Admin\ProductController::class, 'index'], 'admin.products');
        $r->post('/products', [Admin\ProductController::class, 'handle'], 'admin.products.submit');
        $r->get('/categories', [Admin\CategoryController::class, 'index'], 'admin.categories');
        $r->post('/categories', [Admin\CategoryController::class, 'handle'], 'admin.categories.submit');
        $r->get('/orders', [Admin\OrderController::class, 'index'], 'admin.orders');
        $r->get('/orders/{id:\d+}', [Admin\OrderController::class, 'show'], 'admin.order');
        $r->post('/orders/{id:\d+}', [Admin\OrderController::class, 'handle'], 'admin.order.submit');
        $r->get('/shipping', [Admin\ShippingController::class, 'index'], 'admin.shipping');
        $r->post('/shipping', [Admin\ShippingController::class, 'handle'], 'admin.shipping.submit');
        $r->get('/analytics', [Admin\AnalyticsController::class, 'index'], 'admin.analytics');
        $r->get('/settings', [Admin\SettingsController::class, 'index'], 'admin.settings');
        $r->post('/settings', [Admin\SettingsController::class, 'handle'], 'admin.settings.submit');
        $r->get('/settings/blank-theme', [Admin\SettingsController::class, 'blankTheme'], 'admin.settings.blank_theme');
        $r->post('/api/magic-edit', [Admin\MagicEditController::class, 'suggest'], 'admin.api.magic_edit');
    }, static fn (Route $route) => $route->admin());
};
