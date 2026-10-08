<?php

/**
 * Route table: path → controller method. Names (last argument) are used to
 * build URLs: path('product.show', {id: 3}) in views, Router::url() in PHP.
 *
 * ->tracked()     counts the page in Admin → Analytics
 * ->admin()       requires an admin login with at least the 'manager' role;
 *                 ->admin('staff') / ->admin('owner') lower or raise it (staff < manager < owner)
 * ->actionsNeed('manager', ['delete'])  POSTed form actions that need a higher role than the route
 * ->withoutAudit() admin POSTs that change nothing (not written to Admin → Activity log)
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
    // Canonical form is /product/12-green-tea (product_url(product) in views);
    // /product/12 and outdated slugs answer with a 301 to it.
    $r->get('/product/{id:\d+}[-{slug:[^/]+}]', [Shop\ProductController::class, 'show'], 'product.show')->tracked();
    $r->get('/robots.txt', [Shop\SeoController::class, 'robots'], 'robots');
    $r->get('/sitemap.xml', [Shop\SeoController::class, 'sitemap'], 'sitemap');
    // Only reached while a thumbnail doesn't exist yet; afterwards the file is served directly.
    $r->get('/assets/images/thumbs/{width:\d+}/{path:.+}', [Shop\ThumbnailController::class, 'show'], 'thumbnail');
    $r->get('/cart', [Shop\CartController::class, 'show'], 'cart')->tracked();
    $r->get('/checkout', [Shop\CheckoutController::class, 'show'], 'checkout')->tracked();
    $r->post('/checkout', [Shop\CheckoutController::class, 'submit'], 'checkout.submit');
    $r->get('/order/confirmation', [Shop\OrderController::class, 'confirmation'], 'order.confirmation')->tracked();
    $r->get('/order/track', [Shop\OrderController::class, 'track'], 'order.track')->tracked();
    $r->post('/order/track', [Shop\OrderController::class, 'lookup'], 'order.lookup');

    // Customer accounts (optional — guest checkout doesn't need one). Pages
    // behind the sign-in redirect to account.login?next=… themselves.
    $r->get('/account/register', [Shop\AccountAuthController::class, 'showRegister'], 'account.register');
    $r->post('/account/register', [Shop\AccountAuthController::class, 'register'], 'account.register.submit');
    $r->get('/account/login', [Shop\AccountAuthController::class, 'showLogin'], 'account.login');
    $r->post('/account/login', [Shop\AccountAuthController::class, 'login'], 'account.login.submit');
    $r->post('/account/logout', [Shop\AccountAuthController::class, 'logout'], 'account.logout');
    $r->get('/account/forgot-password', [Shop\AccountAuthController::class, 'showForgot'], 'account.forgot');
    $r->post('/account/forgot-password', [Shop\AccountAuthController::class, 'forgot'], 'account.forgot.submit');
    $r->get('/account/reset-password', [Shop\AccountAuthController::class, 'showReset'], 'account.reset');
    $r->post('/account/reset-password', [Shop\AccountAuthController::class, 'reset'], 'account.reset.submit');
    $r->get('/account/verify', [Shop\AccountAuthController::class, 'verify'], 'account.verify');
    $r->post('/account/verify', [Shop\AccountAuthController::class, 'resendVerification'], 'account.verify.resend');
    $r->get('/account', [Shop\AccountController::class, 'overview'], 'account');
    $r->get('/account/orders', [Shop\AccountController::class, 'orders'], 'account.orders');
    $r->get('/account/orders/{number:[A-Za-z0-9-]+}', [Shop\AccountController::class, 'order'], 'account.order');
    $r->get('/account/profile', [Shop\AccountController::class, 'profile'], 'account.profile');
    $r->post('/account/profile', [Shop\AccountController::class, 'updateProfile'], 'account.profile.submit');
    $r->get('/account/addresses', [Shop\AccountController::class, 'addresses'], 'account.addresses');
    $r->post('/account/addresses', [Shop\AccountController::class, 'updateAddresses'], 'account.addresses.submit');
    $r->get('/about', [Shop\PageController::class, 'about'], 'page.about')->tracked();
    $r->get('/info', [Shop\PageController::class, 'info'], 'page.info')->tracked();
    $r->get('/support', [Shop\PageController::class, 'support'], 'page.support')->tracked();
    $r->get('/terms', [Shop\PageController::class, 'terms'], 'page.terms')->tracked();

    // Cart API used by assets/js/app.js. No form token: the session cookie is
    // SameSite=Lax, so other sites can't make the browser POST here with it.
    $r->get('/api/cart', [CartApiController::class, 'show'], 'api.cart');
    $r->post('/api/cart', [CartApiController::class, 'update'], 'api.cart.update')->withoutCsrf();
    // Live checkout totals (shipping, discount, VAT) for assets/js/checkout.js; read-only.
    $r->get('/api/checkout/quote', [Shop\CheckoutController::class, 'quote'], 'api.checkout.quote');

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
    $r->post('/admin/logout', [Admin\AuthController::class, 'logout'], 'admin.logout');

    $r->group('/admin', static function (RouteCollection $r): void {
        // Every admin: dashboard, own account, products, categories (no deletes), orders.
        $r->get('', [Admin\DashboardController::class, 'index'], 'admin.dashboard')->admin('staff');
        $r->get('/account', [Admin\AccountController::class, 'index'], 'admin.account')->admin('staff');
        $r->post('/account', [Admin\AccountController::class, 'handle'], 'admin.account.submit')->admin('staff');
        $r->get('/products', [Admin\ProductController::class, 'index'], 'admin.products')->admin('staff');
        $r->post('/products', [Admin\ProductController::class, 'handle'], 'admin.products.submit')
            ->admin('staff')->actionsNeed('manager', ['delete', 'bulk_delete']);
        $r->get('/categories', [Admin\CategoryController::class, 'index'], 'admin.categories')->admin('staff');
        $r->post('/categories', [Admin\CategoryController::class, 'handle'], 'admin.categories.submit')
            ->admin('staff')->actionsNeed('manager', ['delete']);
        $r->get('/orders', [Admin\OrderController::class, 'index'], 'admin.orders')->admin('staff');
        $r->get('/orders/{id:\d+}', [Admin\OrderController::class, 'show'], 'admin.order')->admin('staff');
        $r->post('/orders/{id:\d+}', [Admin\OrderController::class, 'handle'], 'admin.order.submit')->admin('staff');
        // Managers (the group default): shipping, analytics, activity log.
        $r->get('/activity', [Admin\ActivityController::class, 'index'], 'admin.activity');
        // CSV export / import (bulk data: customer details, every price).
        $r->get('/products/export', [Admin\ProductCsvController::class, 'export'], 'admin.products.export');
        $r->get('/products/import', [Admin\ProductCsvController::class, 'form'], 'admin.products.import');
        $r->post('/products/import', [Admin\ProductCsvController::class, 'handle'], 'admin.products.import.submit');
        // JSON export / import: products with variants, translations and images.
        $r->get('/products/export-json', [Admin\ProductJsonController::class, 'export'], 'admin.products.export_json');
        $r->get('/products/import-json', [Admin\ProductJsonController::class, 'form'], 'admin.products.import_json');
        $r->post('/products/import-json', [Admin\ProductJsonController::class, 'handle'], 'admin.products.import_json.submit');
        $r->get('/orders/export', [Admin\OrderExportController::class, 'export'], 'admin.orders.export');
        $r->get('/shipping', [Admin\ShippingController::class, 'index'], 'admin.shipping');
        $r->post('/shipping', [Admin\ShippingController::class, 'handle'], 'admin.shipping.submit');
        $r->get('/discounts', [Admin\DiscountController::class, 'index'], 'admin.discounts');
        $r->post('/discounts', [Admin\DiscountController::class, 'handle'], 'admin.discounts.submit');
        $r->get('/analytics', [Admin\AnalyticsController::class, 'index'], 'admin.analytics');
        // Customer accounts (personal data): list, detail, deactivate.
        $r->get('/customers', [Admin\CustomerController::class, 'index'], 'admin.customers');
        $r->get('/customers/{id:\d+}', [Admin\CustomerController::class, 'show'], 'admin.customer');
        $r->post('/customers/{id:\d+}', [Admin\CustomerController::class, 'handle'], 'admin.customer.submit');
        // Owners only: settings (store, tax, themes) and users.
        $r->get('/settings', [Admin\SettingsController::class, 'index'], 'admin.settings')->admin('owner');
        $r->post('/settings', [Admin\SettingsController::class, 'handle'], 'admin.settings.submit')->admin('owner');
        $r->get('/settings/blank-theme', [Admin\SettingsController::class, 'blankTheme'], 'admin.settings.blank_theme')->admin('owner');
        $r->get('/users', [Admin\UserController::class, 'index'], 'admin.users')->admin('owner');
        $r->post('/users', [Admin\UserController::class, 'handle'], 'admin.users.submit')->admin('owner');
        $r->post('/api/magic-edit', [Admin\MagicEditController::class, 'suggest'], 'admin.api.magic_edit')->admin('staff')->withoutAudit();
        $r->post('/api/translate-product', [Admin\ProductTranslationController::class, 'suggest'], 'admin.api.translate')->admin('staff')->withoutAudit();
    }, static fn (Route $route) => $route->admin());
};
