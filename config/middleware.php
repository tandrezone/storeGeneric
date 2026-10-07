<?php

/**
 * PSR-15 middleware, outermost first. Each wraps everything below it;
 * App\Http\ControllerDispatcher runs the matched controller at the end.
 */

declare(strict_types=1);

use App\Http\Middleware;

return [
    Middleware\AdminSecurityHeadersMiddleware::class, // CSP etc. on /admin responses (error pages too); nosniff + Referrer-Policy everywhere
    Middleware\ErrorHandlerMiddleware::class, // exceptions → error page / JSON, logging
    Middleware\SessionMiddleware::class,      // start the session (hardened cookie)
    Middleware\LocaleMiddleware::class,       // language: admin user's, or ?lang= / session / cookie / store default
    Middleware\LegacyUrlMiddleware::class,    // old *.php URLs → 301 to clean routes
    Middleware\RoutingMiddleware::class,      // match route (404 / 405)
    Middleware\AdminAuthMiddleware::class,    // login wall + role check for ->admin() routes
    Middleware\CsrfMiddleware::class,         // form token on POST
    Middleware\AdminAuditMiddleware::class,   // activity log entry for successful admin POSTs
    Middleware\PageViewMiddleware::class,     // analytics for ->tracked() routes
];
