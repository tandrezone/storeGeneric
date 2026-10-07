<?php

/**
 * PSR-15 middleware, outermost first. Each wraps everything below it;
 * App\Http\ControllerDispatcher runs the matched controller at the end.
 */

declare(strict_types=1);

use App\Http\Middleware;

return [
    Middleware\ErrorHandlerMiddleware::class, // exceptions → error page / JSON, logging
    Middleware\SessionMiddleware::class,      // start the session (hardened cookie)
    Middleware\LegacyUrlMiddleware::class,    // old *.php URLs → 301 to clean routes
    Middleware\RoutingMiddleware::class,      // match route (404 / 405)
    Middleware\AdminAuthMiddleware::class,    // login wall for ->admin() routes
    Middleware\CsrfMiddleware::class,         // form token on POST
    Middleware\PageViewMiddleware::class,     // analytics for ->tracked() routes
];
