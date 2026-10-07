<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Permanently redirects the old page-per-file URLs (/product.php?id=3,
 * /admin/orders.php …) to the clean routes, keeping bookmarks and search
 * results working. GETs get a 301; other methods a 308 so the body is kept.
 *
 * Payment webhook URLs (/payment/*.php) are NOT redirected — they are real
 * routes, because providers don't reliably follow redirects.
 */
final class LegacyUrlMiddleware implements MiddlewareInterface
{
    /** Old path => new path, for pages whose query string carries over unchanged. */
    private const SIMPLE = [
        '/index.php'                => '/',
        '/cart.php'                 => '/cart',
        '/checkout.php'             => '/checkout',
        '/about.php'                => '/about',
        '/info.php'                 => '/info',
        '/support.php'              => '/support',
        '/terms.php'                => '/terms',
        '/order-confirmation.php'   => '/order/confirmation',
        '/api/cart.php'             => '/api/cart',
        '/admin'                    => '/admin/products',
        '/admin/index.php'          => '/admin/products',
        '/admin/products.php'       => '/admin/products',
        '/admin/categories.php'     => '/admin/categories',
        '/admin/orders.php'         => '/admin/orders',
        '/admin/shipping.php'       => '/admin/shipping',
        '/admin/analytics.php'      => '/admin/analytics',
        '/admin/login.php'          => '/admin/login',
        '/admin/logout.php'         => '/admin/logout',
        '/admin/api/magic-edit.php' => '/admin/api/magic-edit',
    ];

    public function __construct(private readonly Responder $responder)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $target = $this->target($request);
        if ($target === null) {
            return $handler->handle($request);
        }

        $status = in_array($request->getMethod(), ['GET', 'HEAD'], true) ? 301 : 308;

        return $this->responder->redirect($target, $status);
    }

    private function target(ServerRequestInterface $request): ?string
    {
        $path = rtrim($request->getUri()->getPath(), '/') ?: '/';
        $query = $request->getQueryParams();

        if ($path === '/product.php') {
            $id = (int) ($query['id'] ?? 0);

            return $id > 0 ? '/product/' . $id : '/';
        }
        if ($path === '/admin/order.php') {
            $id = (int) ($query['id'] ?? 0);

            return $id > 0 ? '/admin/orders/' . $id : '/admin/orders';
        }
        if ($path === '/admin/settings.php') {
            return ($query['download'] ?? '') === 'blank-theme' ? '/admin/settings/blank-theme' : '/admin/settings';
        }
        if (!isset(self::SIMPLE[$path])) {
            return null;
        }

        if ($path === '/order-confirmation.php' && isset($query['order_id']) && !isset($query['order'])) {
            $query['order'] = $query['order_id'];
            unset($query['order_id']);
        }

        return self::SIMPLE[$path] . ($query ? '?' . http_build_query($query) : '');
    }
}
