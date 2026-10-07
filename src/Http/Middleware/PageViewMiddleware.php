<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\RouteMatch;
use App\Http\Session;
use App\Repository\PageViewRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Records a "visit" for successful GETs of routes marked ->tracked() (storefront pages). */
final class PageViewMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly PageViewRepository $pageViews,
        private readonly Session $session,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $match = $request->getAttribute(RouteMatch::ATTRIBUTE);
        if (
            $match instanceof RouteMatch
            && $match->route->isTracked()
            && $request->getMethod() === 'GET'
            && $response->getStatusCode() === 200
        ) {
            $this->pageViews->record($this->session->id(), 'visit', null, $request->getUri()->getPath());
        }

        return $response;
    }
}
