<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Content-Security-Policy and friends for every /admin response (error pages
 * included). Admin views load scripts only from files (no inline <script>,
 * no on*= handlers), so script-src 'self' is enough; style attributes are
 * still used, hence 'unsafe-inline' for styles only. img-src/connect-src stay
 * on our own origin so a stylesheet can't leak page content elsewhere.
 *
 * Storefront responses get only X-Content-Type-Options and a Referrer-Policy
 * that keeps paths and query strings (the order links' ?key=…) from leaking
 * to other sites; no CSP there, as themes may use inline scripts and styles.
 */
final class AdminSecurityHeadersMiddleware implements MiddlewareInterface
{
    private const CSP = "default-src 'self'; "
        . "script-src 'self'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob:; "
        . "font-src 'self' data:; "
        . "connect-src 'self'; "
        . "object-src 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'none'";

    /** Defaults for every other response (a controller may set its own). */
    private const STOREFRONT_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy'        => 'strict-origin-when-cross-origin',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $path = $request->getUri()->getPath();
        if ($path !== '/admin' && !str_starts_with($path, '/admin/')) {
            foreach (self::STOREFRONT_HEADERS as $name => $value) {
                if (!$response->hasHeader($name)) {
                    $response = $response->withHeader($name, $value);
                }
            }

            return $response;
        }

        return $response
            ->withHeader('Content-Security-Policy', self::CSP)
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('Cache-Control', 'no-store');
    }
}
