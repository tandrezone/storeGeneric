<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Responder;
use App\Http\Session;
use App\I18n\Translator;
use App\Repository\AdminUserRepository;
use App\Security\AdminAuthenticator;
use App\Service\AuditLog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Admin → My account (every role): choose the admin language, change your own password. */
final class AccountController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly AdminAuthenticator $auth,
        private readonly AuditLog $audit,
        private readonly AdminUserRepository $users,
        private readonly Translator $translator,
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

    /** @param list<string> $errors */
    private function page(ServerRequestInterface $request, array $errors = [], int $status = 200): ResponseInterface
    {
        $me = $this->auth->user();

        return $this->responder->view($request, 'admin/account.html.twig', [
            'me'           => $me,
            'my_locale'    => $this->translator->normalize(is_string($me['locale'] ?? null) ? $me['locale'] : null) ?? Translator::DEFAULT_LOCALE,
            'errors'       => $errors,
            'min_password' => AdminAuthenticator::MIN_PASSWORD_LENGTH,
        ], $status);
    }
}
