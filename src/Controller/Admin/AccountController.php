<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Responder;
use App\Http\Session;
use App\Security\AdminAuthenticator;
use App\Service\AuditLog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Admin → My account (every role): change your own password. */
final class AccountController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly AdminAuthenticator $auth,
        private readonly AuditLog $audit,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $me = (array) $this->auth->user();
        $new = (string) ($body['new_password'] ?? '');

        $error = match (true) {
            !$this->auth->verifyPassword((int) $me['id'], (string) ($body['current_password'] ?? '')) => 'Your current password is not correct.',
            AdminAuthenticator::passwordProblem($new) !== null => AdminAuthenticator::passwordProblem($new),
            !hash_equals($new, (string) ($body['new_password_confirm'] ?? '')) => "The two new passwords don't match.",
            default => null,
        };
        if ($error !== null) {
            return $this->page($request, [$error], 422);
        }

        $this->auth->changePassword((int) $me['id'], $new);
        $this->audit->record($request, $me, 'account.change_password', 'admin_user', (int) $me['id'], 'Changed own password');
        $this->session->flash('success', 'Password changed. Your other sessions are logged out.');

        return $this->responder->redirectToRoute('admin.account');
    }

    /** @param list<string> $errors */
    private function page(ServerRequestInterface $request, array $errors = [], int $status = 200): ResponseInterface
    {
        return $this->responder->view($request, 'admin/account.html.twig', [
            'me'           => $this->auth->user(),
            'errors'       => $errors,
            'min_password' => AdminAuthenticator::MIN_PASSWORD_LENGTH,
        ], $status);
    }
}
