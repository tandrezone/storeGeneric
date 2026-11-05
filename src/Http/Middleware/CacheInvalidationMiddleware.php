<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\FileCache;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Drops the storefront data cache after any successful change made in the admin
 * (products, prices, stock, categories, pages, settings, …), so visitors never see
 * a stale catalogue for more than the moment it takes to reload.
 */
final class CacheInvalidationMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly FileCache $cache)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        if ($request->getMethod() === 'POST' && str_starts_with($request->getUri()->getPath(), '/admin') && $response->getStatusCode() < 400) {
            $this->cache->bump();
        }

        return $response;
    }
}
