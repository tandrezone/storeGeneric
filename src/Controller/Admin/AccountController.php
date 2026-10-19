<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\ClientIp;
use App\Http\Responder;
use App\Http\Session;
use App\I18n\Translator;
use App\Repository\AdminUserRepository;
use App\Security\AdminAuthenticator;
use App\Security\Totp;
use App\Service\AuditLog;
use App\Service\StoreSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Admin → My account (every role): admin language, own password, two-factor login (TOTP). */
final class AccountController
{
    /** Session key for the secret shown during setup; it is only saved once a code confirms it. */
    private const SESSION_SETUP = 'admin_2fa_setup';

    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly AdminAuthenticator $auth,
        private readonly AuditLog $audit,
        private readonly AdminUserRepository $users,
        private readonly Translator $translator,
        private readonly ClientIp $clientIp,
        private readonly StoreSettings $store,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();

        // A form without an action is the password form (older pages).
        return match ((string) ($body['action'] ?? 'change_password')) {
            'save_language' => $this->saveLanguage($request, $body),
            '2fa_start'     => $this->startTwoFactor($request),
            '2fa_enable'    => $this->enableTwoFactor($request, $body),
            '2fa_disable'   => $this->disableTwoFactor($request, $body),
            default         => $this->changePassword($request, $body),
        };
    }

    /** @param array<string, mixed> $body */
    private function saveLanguage(ServerRequestInterface $request, array $body): ResponseInterface
    {
        $me = (array) $this->auth->user();
        $locale = $this->translator->normalize((string) ($body['locale'] ?? ''));
        if ($locale === null) {
            return $this->page($request, [$this->translator->trans('Choose one of the available languages.')], 422);
        }

        $this->users->setLocale((int) $me['id'], $locale);
        if (($me['locale'] ?? null) !== $locale) {
            $this->audit->record($request, $me, 'account.language', 'admin_user', (int) $me['id'], "Changed own language to {$locale}");
        }
        // The confirmation is already in the new language.
        $this->translator->setLocale($locale);
        $this->session->flash('success', $this->translator->trans('Language saved.'));

        return $this->responder->redirectToRoute('admin.account');
    }

    /** @param array<string, mixed> $body */
    private function changePassword(ServerRequestInterface $request, array $body): ResponseInterface
    {
        $me = (array) $this->auth->user();
        $new = (string) ($body['new_password'] ?? '');
        $problem = AdminAuthenticator::passwordProblem($new, $this->translator);

        $error = match (true) {
            !$this->auth->verifyPassword((int) $me['id'], (string) ($body['current_password'] ?? '')) => $this->translator->trans('Your current password is not correct.'),
            $problem !== null => $problem,
            !hash_equals($new, (string) ($body['new_password_confirm'] ?? '')) => $this->translator->trans("The two new passwords don't match."),
            default => null,
        };
        if ($error !== null) {
            return $this->page($request, [$error], 422);
        }

        $this->auth->changePassword((int) $me['id'], $new);
        $this->audit->record($request, $me, 'account.change_password', 'admin_user', (int) $me['id'], 'Changed own password');
        $this->session->flash('success', $this->translator->trans('Password changed. Your other sessions are logged out.'));

        return $this->responder->redirectToRoute('admin.account');
    }

    /** Shows a new secret (nothing is saved until a code from the app confirms it). */
    private function startTwoFactor(ServerRequestInterface $request): ResponseInterface
    {
        $me = (array) $this->auth->user();
        if ((bool) ($me['totp_enabled'] ?? false)) {
            return $this->responder->redirectToRoute('admin.account');
        }
        $this->session->set(self::SESSION_SETUP, Totp::generateSecret());

        return $this->page($request);
    }

    /** @param array<string, mixed> $body */
    private function enableTwoFactor(ServerRequestInterface $request, array $body): ResponseInterface
    {
        $me = (array) $this->auth->user();
        $secret = $this->session->get(self::SESSION_SETUP);
        if ((bool) ($me['totp_enabled'] ?? false) || !is_string($secret)) {
            return $this->responder->redirectToRoute('admin.account');
        }

        $codes = $this->auth->enableTwoFactor((int) $me['id'], $secret, (string) ($body['totp_code'] ?? ''));
        if ($codes === null) {
            return $this->page($request, [$this->translator->trans('That code is not correct. Check the time on your phone and try again.')], 422);
        }

        $this->session->remove(self::SESSION_SETUP);
        $this->audit->record($request, $me, 'account.2fa_enable', 'admin_user', (int) $me['id'], 'Turned on two-factor login');

        // Shown once, right here: only their hashes are stored.
        return $this->page($request, [], 200, $codes);
    }

    /** @param array<string, mixed> $body */
    private function disableTwoFactor(ServerRequestInterface $request, array $body): ResponseInterface
    {
        $me = (array) $this->auth->user();
        if (!(bool) ($me['totp_enabled'] ?? false)) {
            return $this->responder->redirectToRoute('admin.account');
        }

        if (!$this->auth->verifyPassword((int) $me['id'], (string) ($body['current_password'] ?? ''))) {
            return $this->page($request, [$this->translator->trans('Your current password is not correct.')], 422);
        }
        if ($this->auth->verifySecondFactor((int) $me['id'], (string) ($body['totp_code'] ?? ''), $this->clientIp->of($request)) === null) {
            $message = $this->auth->lastFailure() === 'locked'
                ? $this->translator->trans('Too many attempts. Try again later.')
                : $this->translator->trans('That code is not correct.');

            return $this->page($request, [$message], 422);
        }

        $this->auth->disableTwoFactor((int) $me['id']);
        $this->audit->record($request, $me, 'account.2fa_disable', 'admin_user', (int) $me['id'], 'Turned off two-factor login');
        $this->session->flash('success', $this->translator->trans('Two-factor login is now off.'));

        return $this->responder->redirectToRoute('admin.account');
    }

    /**
     * @param list<string> $errors
     * @param list<string> $recoveryCodes shown once, right after two-factor login was turned on
     */
    private function page(ServerRequestInterface $request, array $errors = [], int $status = 200, array $recoveryCodes = []): ResponseInterface
    {
        $me = $this->auth->user();
        $setupSecret = $this->session->get(self::SESSION_SETUP);
        $setupSecret = is_string($setupSecret) && !(bool) ($me['totp_enabled'] ?? false) ? $setupSecret : null;

        return $this->responder->view($request, 'admin/account.html.twig', [
            'me'           => $me,
            'my_locale'    => $this->translator->normalize(is_string($me['locale'] ?? null) ? $me['locale'] : null) ?? Translator::DEFAULT_LOCALE,
            'errors'       => $errors,
            'min_password' => AdminAuthenticator::MIN_PASSWORD_LENGTH,
            'totp_enabled' => (bool) ($me['totp_enabled'] ?? false),
            'totp_secret'  => $setupSecret,
            'totp_uri'     => $setupSecret === null ? null : Totp::uri($setupSecret, (string) $me['username'], $this->store->name()),
            'recovery_codes' => $recoveryCodes,
        ], $status);
    }
}
