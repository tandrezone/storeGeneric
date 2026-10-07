<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Exception\HttpException;
use App\Http\RouteMatch;
use App\Security\Csrf;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rejects state-changing requests (POST/PUT/PATCH/DELETE) without a valid
 * token in the `csrf_token` field or X-CSRF-Token header. Routes marked
 * ->withoutCsrf() (webhooks) are skipped.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Csrf $csrf)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $match = $request->getAttribute(RouteMatch::ATTRIBUTE);
        $unsafe = !in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true);

        if ($unsafe && $match instanceof RouteMatch && $match->route->requiresCsrf()) {
            $body = $request->getParsedBody();
            $token = is_array($body) ? ($body[Csrf::FIELD] ?? null) : null;
            $token ??= $request->getHeaderLine(Csrf::HEADER) ?: null;

            if (!$this->csrf->isValid(is_string($token) ? $token : null)) {
                throw HttpException::badRequest('Your session expired or the form was already used. Please go back, reload the page and try again.');
            }
        }

        return $handler->handle($request);
    }
}
