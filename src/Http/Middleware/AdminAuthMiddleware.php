<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Exception\HttpException;
use App\Http\Responder;
use App\Http\RouteMatch;
use App\Http\Session;
use App\I18n\Translator;
use App\Security\AdminAuthenticator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Login wall and role check for routes marked ->admin($role): visitors
 * without a session go to the login page (401 for the admin API); admins
 * whose role is below the route's — or below the role a POSTed `action`
 * needs (Route::actionsNeed) — get a 403.
 */
final class AdminAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AdminAuthenticator $auth,
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly Translator $translator,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $match = $request->getAttribute(RouteMatch::ATTRIBUTE);
        if (!$match instanceof RouteMatch || !$match->route->requiresAdmin()) {
            return $handler->handle($request);
        }

        $role = $this->auth->role();
        if ($role === null) {
            if (str_starts_with($request->getUri()->getPath(), '/admin/api/')) {
                throw new HttpException(401, 'Not authenticated.');
            }
            if ($this->auth->wasRevoked()) {
                $this->session->flash('error', $this->translator->trans('You have been logged out: your account was deactivated or its password was changed.'));
            }

            return $this->responder->redirectToRoute('admin.login');
        }

        $required = $match->route->adminRole();
        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            $action = is_array($body) && is_string($body['action'] ?? null) ? $body['action'] : '';
            $required = $match->route->roleForAction($action);
        }

        if ($required !== null && !$role->includes($required)) {
            throw new HttpException(403, $this->translator->trans("Your account's role ({role}) doesn't allow this. Ask the store owner if you need access.", [
                'role' => $this->translator->trans($role->label()),
            ]));
        }

        return $handler->handle($request);
    }
}
