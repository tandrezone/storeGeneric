<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\RouteMatch;
use App\Http\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Matches the request to a route and stores the result as a request attribute. */
final class RoutingMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Router $router)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = rawurldecode($request->getUri()->getPath()) ?: '/';
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $match = $this->router->match($request->getMethod(), $path);

        return $handler->handle($request->withAttribute(RouteMatch::ATTRIBUTE, $match));
    }
}
