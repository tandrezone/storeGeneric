<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Session;
use App\I18n\Translator;
use App\Repository\CustomerRepository;
use App\Security\AdminAuthenticator;
use App\Security\CustomerAuthenticator;
use App\Service\StoreSettings;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Picks the language of the request (App\I18n\Translator):
 *  - /admin: the admin user's own language (Admin → My account), else English;
 *  - storefront: ?lang=xx (remembered in the session, a cookie and — when
 *    signed in — the customer account), then the session, the cookie, the
 *    signed-in customer's language, and finally the store default
 *    (Admin → Settings / STORE_LANGUAGE).
 * ?lang= doesn't redirect, so links like /product/1?lang=pt can be shared
 * and used as hreflang alternates.
 */
final class LocaleMiddleware implements MiddlewareInterface
{
    public const QUERY = 'lang';
    public const COOKIE = 'store_lang';
    private const SESSION_KEY = 'locale';
    private const COOKIE_DAYS = 365;

    public function __construct(
        private readonly Translator $translator,
        private readonly Session $session,
        private readonly StoreSettings $store,
        private readonly AdminAuthenticator $admin,
        private readonly CustomerAuthenticator $customers,
        private readonly CustomerRepository $customerRepository,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            $this->translator->setLocale($this->adminLocale());

            return $handler->handle($request);
        }

        $query = $request->getQueryParams()[self::QUERY] ?? null;
        $chosen = is_string($query) ? $this->translator->normalize($query) : null;
        $cookie = $request->getCookieParams()[self::COOKIE] ?? null;

        if ($chosen !== null) {
            $this->session->set(self::SESSION_KEY, $chosen);
            $this->rememberForCustomer($chosen);
            $locale = $chosen;
        } else {
            $session = $this->session->get(self::SESSION_KEY);
            $locale = $this->translator->normalize(is_string($session) ? $session : null)
                ?? $this->translator->normalize(is_string($cookie) ? $cookie : null)
                ?? $this->customerLocale()
                ?? $this->translator->normalize($this->storeLanguage())
                ?? Translator::DEFAULT_LOCALE;
        }
        $this->translator->setLocale($locale);

        $response = $handler->handle($request);
        if ($chosen !== null && $cookie !== $chosen) {
            $response = $response->withAddedHeader('Set-Cookie', $this->cookie($chosen, $request));
        }

        return $response;
    }

    private function adminLocale(): ?string
    {
        try {
            $locale = $this->admin->user()['locale'] ?? null;
        } catch (PDOException) {
            return null; // no database: English
        }

        return is_string($locale) ? $locale : null;
    }

    private function customerLocale(): ?string
    {
        try {
            $locale = $this->customers->customer()['locale'] ?? null;
        } catch (PDOException) {
            return null;
        }

        return $this->translator->normalize(is_string($locale) ? $locale : null);
    }

    private function rememberForCustomer(string $locale): void
    {
        try {
            $customer = $this->customers->customer();
            if ($customer !== null && ($customer['locale'] ?? null) !== $locale) {
                $this->customerRepository->setLocale((int) $customer['id'], $locale);
            }
        } catch (PDOException) {
            // Not signed in as far as we can tell; the session and cookie still remember it.
        }
    }

    private function storeLanguage(): ?string
    {
        try {
            return $this->store->language();
        } catch (PDOException) {
            return null;
        }
    }

    private function cookie(string $locale, ServerRequestInterface $request): string
    {
        $secure = $request->getUri()->getScheme() === 'https'
            || strtolower($request->getHeaderLine('X-Forwarded-Proto')) === 'https';

        return self::COOKIE . '=' . rawurlencode($locale)
            . '; Path=/; Max-Age=' . (self::COOKIE_DAYS * 86400)
            . '; SameSite=Lax; HttpOnly' . ($secure ? '; Secure' : '');
    }
}
