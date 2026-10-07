<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Http\ClientIp;
use App\Http\Responder;
use App\Http\Router;
use App\Http\Session;
use App\Security\CustomerAuthenticator;
use App\Service\CustomerAccounts;
use App\Service\OrderLinks;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * Customer sign-in pages: register, sign in / out, forgot + reset password,
 * email verification. Signing in or out changes the header and the form
 * token, so those forms opt out of ajax-nav.js (data-no-ajax) and reload
 * the whole page. ?next=/path returns to a local page afterwards (e.g. the
 * checkout).
 */
final class AccountAuthController
{
    private const RESEND_KEY = 'customer_verify_sent_at';
    private const RESEND_SECONDS = 60;

    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly CustomerAuthenticator $auth,
        private readonly CustomerAccounts $accounts,
        private readonly OrderLinks $links,
        private readonly ClientIp $clientIp,
        private readonly Router $router,
    ) {
    }

    public function showLogin(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->auth->isLoggedIn()) {
            return $this->responder->redirect($this->next($request));
        }

        return $this->loginForm($request, '', []);
    }

    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $email = trim((string) ($body['email'] ?? ''));

        if ($this->auth->attempt($email, (string) ($body['password'] ?? ''), $this->clientIp->of($request))) {
            return $this->responder->redirect($this->next($request));
        }

        if ($this->auth->lastFailure() === 'locked') {
            return $this->loginForm($request, $email, [
                sprintf('Too many sign-in attempts. Please wait %d minutes and try again, or reset your password.', CustomerAuthenticator::LOCKOUT_MINUTES),
            ], 429);
        }

        return $this->loginForm($request, $email, ['Wrong email or password.'], 401);
    }

    /** POST only (with the form token), so other sites can't sign the customer out. */
    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        $this->auth->logout();
        // On a shared computer the next visitor mustn't see orders placed in this session.
        $this->links->forgetPlaced();
        $this->session->remove(self::RESEND_KEY);
        $this->session->flash('success', 'You have been signed out.');

        return $this->responder->redirectToRoute('account.login');
    }

    public function showRegister(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->auth->isLoggedIn()) {
            return $this->responder->redirectToRoute('account');
        }

        return $this->registerForm($request, [], []);
    }

    public function register(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        try {
            $customer = $this->accounts->register($body, $this->clientIp->of($request));
        } catch (RuntimeException $e) {
            return $this->registerForm($request, $body, [$e->getMessage()], 422);
        }

        $this->auth->login($customer);
        $this->session->set(self::RESEND_KEY, time());
        $this->session->flash('success', "Welcome, {$customer['name']}! We sent a link to {$customer['email']} to confirm your email address.");

        return $this->responder->redirect($this->next($request, 'account'));
    }

    public function showForgot(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->view($request, 'shop/account/forgot-password.html.twig', [
            'email' => (string) ($request->getQueryParams()['email'] ?? ''), 'errors' => [], 'sent' => false,
        ]);
    }

    /** Same answer whether or not an account exists, so the form can't be used to find accounts. */
    public function forgot(ServerRequestInterface $request): ResponseInterface
    {
        $email = trim((string) (((array) $request->getParsedBody())['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->responder->view($request, 'shop/account/forgot-password.html.twig', [
                'email' => $email, 'errors' => ['Please enter a valid email address.'], 'sent' => false,
            ], 422);
        }
        if (!$this->accounts->requestPasswordReset($email, $this->clientIp->of($request))) {
            return $this->responder->view($request, 'shop/account/forgot-password.html.twig', [
                'email' => $email, 'errors' => ['Too many reset requests. Please try again in an hour.'], 'sent' => false,
            ], 429);
        }

        return $this->responder->view($request, 'shop/account/forgot-password.html.twig', [
            'email' => $email, 'errors' => [], 'sent' => true, 'minutes' => CustomerAccounts::RESET_MINUTES,
        ]);
    }

    public function showReset(ServerRequestInterface $request): ResponseInterface
    {
        $token = (string) ($request->getQueryParams()['token'] ?? '');

        return $this->responder->view($request, 'shop/account/reset-password.html.twig', [
            'token'        => $token,
            'valid'        => $this->accounts->isResetTokenValid($token),
            'min_password' => CustomerAuthenticator::MIN_PASSWORD_LENGTH,
            'errors'       => [],
        ]);
    }

    public function reset(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $token = (string) ($body['token'] ?? '');
        try {
            $customer = $this->accounts->resetPassword($token, $body);
        } catch (RuntimeException $e) {
            return $this->responder->view($request, 'shop/account/reset-password.html.twig', [
                'token'        => $token,
                'valid'        => $this->accounts->isResetTokenValid($token),
                'min_password' => CustomerAuthenticator::MIN_PASSWORD_LENGTH,
                'errors'       => [$e->getMessage()],
            ], 422);
        }

        $this->auth->login($customer);
        $this->session->flash('success', 'Your password has been changed and you are signed in.');

        return $this->responder->redirectToRoute('account');
    }

    /** The link from the welcome / change-of-email message. Doesn't need to be signed in. */
    public function verify(ServerRequestInterface $request): ResponseInterface
    {
        $result = $this->accounts->verifyEmail((string) ($request->getQueryParams()['token'] ?? ''));
        if ($result === null) {
            return $this->responder->view($request, 'shop/account/verify.html.twig', [
                'customer' => $this->auth->customer(),
            ], 410);
        }

        $message = 'Thank you — your email address is confirmed.';
        if ($result['linked'] > 0) {
            $message .= sprintf(' %d earlier order%s placed with it %s now in your account.', $result['linked'], $result['linked'] === 1 ? '' : 's', $result['linked'] === 1 ? 'is' : 'are');
        }
        $this->session->flash('success', $message);

        return $this->responder->redirectToRoute($this->auth->id() === $result['customer_id'] ? 'account' : 'account.login');
    }

    /** "Send the link again" (signed in, at most once a minute). */
    public function resendVerification(ServerRequestInterface $request): ResponseInterface
    {
        $customer = $this->auth->customer();
        if ($customer === null) {
            return $this->responder->redirectToRoute('account.login', [], ['next' => '/account']);
        }
        if ($customer['email_verified_at'] !== null) {
            $this->session->flash('success', 'Your email address is already confirmed.');

            return $this->responder->redirectToRoute('account');
        }

        $last = (int) $this->session->get(self::RESEND_KEY, 0);
        if ($last > time() - self::RESEND_SECONDS) {
            $this->session->flash('error', 'We just sent you a link. Please wait a minute before asking for another one.');
        } elseif ($this->accounts->sendVerification($customer)) {
            $this->session->set(self::RESEND_KEY, time());
            $this->session->flash('success', "We sent a new confirmation link to {$customer['email']}.");
        } else {
            $this->session->flash('error', 'We couldn\'t send the email right now. Please try again later.');
        }

        return $this->responder->redirectToRoute('account');
    }

    /** @param list<string> $errors */
    private function loginForm(ServerRequestInterface $request, string $email, array $errors, int $status = 200): ResponseInterface
    {
        return $this->responder->view($request, 'shop/account/login.html.twig', [
            'email'  => $email,
            'errors' => $errors,
            'next'   => $this->nextParam($request),
        ], $status);
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string>         $errors
     */
    private function registerForm(ServerRequestInterface $request, array $input, array $errors, int $status = 200): ResponseInterface
    {
        return $this->responder->view($request, 'shop/account/register.html.twig', [
            'form'         => ['name' => (string) ($input['name'] ?? ''), 'email' => (string) ($input['email'] ?? '')],
            'errors'       => $errors,
            'next'         => $this->nextParam($request),
            'min_password' => CustomerAuthenticator::MIN_PASSWORD_LENGTH,
        ], $status);
    }

    /** Where to go after signing in: ?next= / the posted next when it's a local path, else the account page. */
    private function next(ServerRequestInterface $request, string $fallbackRoute = 'account'): string
    {
        $next = $this->nextParam($request);

        return $next !== '' ? $next : $this->router->url($fallbackRoute);
    }

    /** The requested local path to return to ('' if none or not local). */
    private function nextParam(ServerRequestInterface $request): string
    {
        $body = $request->getParsedBody();
        $next = (string) ((is_array($body) ? ($body['next'] ?? null) : null) ?? $request->getQueryParams()['next'] ?? '');

        return self::isLocalPath($next) ? $next : '';
    }

    /** "/checkout" yes; "//evil.example", "/\evil", "https://…" or control characters no. */
    public static function isLocalPath(string $path): bool
    {
        return $path !== '' && strlen($path) <= 500 && $path[0] === '/'
            && !str_starts_with($path, '//') && !str_starts_with($path, '/\\')
            && preg_match('/[\x00-\x1F\x7F\\\\]/', $path) !== 1;
    }
}
