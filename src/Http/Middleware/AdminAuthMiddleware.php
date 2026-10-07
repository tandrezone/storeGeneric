<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Exception\HttpException;
use App\Http\Responder;
use App\Http\RouteMatch;
use App\Security\AdminAuthenticator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Sends visitors to the login page when a route marked ->admin() is requested without a session. */
final class AdminAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AdminAuthenticator $auth,
        private readonly Responder $responder,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $match = $request->getAttribute(RouteMatch::ATTRIBUTE);
        if (!$match instanceof RouteMatch || !$match->route->requiresAdmin() || $this->auth->isLoggedIn()) {
            return $handler->handle($request);
        }

        if (str_starts_with($request->getUri()->getPath(), '/admin/api/')) {
            throw new HttpException(401, 'Not authenticated.');
        }

        return $this->responder->redirectToRoute('admin.login');
    }
}
