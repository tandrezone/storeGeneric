<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\ClientIp;
use App\Http\Responder;
use App\Http\Session;
use App\I18n\Translator;
use App\Security\AdminAuthenticator;
use App\Service\AuditLog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AuthController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly AdminAuthenticator $auth,
        private readonly ClientIp $clientIp,
        private readonly Session $session,
        private readonly AuditLog $audit,
        private readonly Translator $translator,
    ) {
    }

    public function showLogin(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->auth->isLoggedIn()) {
            return $this->responder->redirectToRoute('admin.dashboard');
        }
        // Coming back to the password form abandons a half-finished two-factor login.
        $this->auth->cancelSecondFactor();

        return $this->responder->view($request, 'admin/login.html.twig', ['error' => null, 'username' => '']);
    }

    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $username = trim((string) ($body['username'] ?? ''));

        if ($this->auth->attempt($username, (string) ($body['password'] ?? ''), $this->clientIp->of($request))) {
            $user = $this->auth->user();
            if ($this->auth->wasBootstrapped()) {
                $this->audit->record($request, $user, 'user.bootstrap', 'admin_user', $user['id'] ?? null, 'Saved the .env admin account as the first owner');
                $this->session->flash('success', $this->translator->trans('Your .env admin account is now the first Owner user. ADMIN_USERNAME / ADMIN_PASSWORD_HASH are no longer used — manage accounts in Users.'));
            }
            $this->audit->record($request, $user, 'login', 'admin_user', $user['id'] ?? null, 'Logged in');

            return $this->responder->redirectToRoute('admin.dashboard');
        }

        if ($this->auth->lastFailure() === 'second_factor') {
            return $this->responder->view($request, 'admin/login_code.html.twig', ['error' => null, 'username' => $username]);
        }

        $reason = $this->auth->lastFailure() === 'locked' ? 'locked out (too many attempts)' : 'wrong username or password';
        $this->audit->record($request, null, 'login.failed', 'admin_user', null, 'Failed login: ' . $reason, [], $username);

        return $this->responder->view($request, 'admin/login.html.twig', [
            'error' => $this->translator->trans('Invalid username or password.'),
            'username' => $username,
        ], 401);
    }

    /** Second login step: the form for the authenticator (or recovery) code. */
    public function showCode(ServerRequestInterface $request): ResponseInterface
    {
        $pending = $this->auth->pendingSecondFactor();
        if ($this->auth->isLoggedIn() || $pending === null) {
            return $this->responder->redirectToRoute($this->auth->isLoggedIn() ? 'admin.dashboard' : 'admin.login');
        }

        return $this->responder->view($request, 'admin/login_code.html.twig', ['error' => null, 'username' => $pending['username']]);
    }

    public function submitCode(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $pending = $this->auth->pendingSecondFactor();

        if ($this->auth->completeSecondFactor((string) ($body['code'] ?? ''), $this->clientIp->of($request))) {
            $user = $this->auth->user();
            $viaRecovery = $this->auth->lastFactor() === 'recovery';
            $this->audit->record($request, $user, 'login', 'admin_user', $user['id'] ?? null, $viaRecovery ? 'Logged in (two-factor, recovery code)' : 'Logged in (two-factor)');
            if ($viaRecovery) {
                $this->audit->record($request, $user, 'account.2fa_recovery_used', 'admin_user', $user['id'] ?? null, 'Used a recovery code to log in');
                $this->session->flash('success', $this->translator->trans('You logged in with a recovery code. Each one works once — turn two-factor off and on again in My account for a fresh set.'));
            }

            return $this->responder->redirectToRoute('admin.dashboard');
        }

        $failure = $this->auth->lastFailure();
        if ($failure === 'expired') {
            $this->session->flash('error', $this->translator->trans('Your login timed out. Enter your password again.'));

            return $this->responder->redirectToRoute('admin.login');
        }
        $this->audit->record(
            $request, null, 'login.failed', 'admin_user', $pending['id'] ?? null,
            'Failed login: ' . ($failure === 'locked' ? 'locked out (too many attempts)' : 'wrong two-factor code'),
            [], $pending['username'] ?? null
        );

        return $this->responder->view($request, 'admin/login_code.html.twig', [
            'error' => $this->translator->trans('Invalid code.'),
            'username' => $pending['username'] ?? '',
        ], 401);
    }

    /** POST only (with the form token), so other sites can't log the admin out. */
    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->auth->user();
        if ($user !== null) {
            $this->audit->record($request, $user, 'logout', 'admin_user', $user['id'], 'Logged out');
        }
        $this->auth->logout();
        $this->session->flash('success', $this->translator->trans('You have been logged out.'));

        return $this->responder->redirectToRoute('admin.login');
    }
}
