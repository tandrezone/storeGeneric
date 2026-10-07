<?php

declare(strict_types=1);

namespace App\View;

use App\Http\Router;
use App\Http\Session;
use App\I18n\Translator;
use App\Security\Csrf;
use App\Service\StoreSettings;
use App\Theme\Theme;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Functions and filters available in application views:
 *   path('route.name', {id: 1})     URL of a named route
 *   asset('css/style.css')          URL of a theme asset
 *   theme_part('header', {...})     rendered theme layout part (sandboxed)
 *   csrf_field() / csrf_token()     form protection
 *   flashes()                       one-time messages queued with Session::flash()
 *   price|money                     "€12.50" / "12,50 €" in STORE_CURRENCY and the active language (App\Support\Money)
 * Theme parts also get lang (<html lang>) and languages ([{code, name,
 * html_lang, url, active}], links that switch the storefront language).
 */
final class ViewExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly Router $router,
        private readonly Theme $theme,
        private readonly Csrf $csrf,
        private readonly Session $session,
        private readonly StoreSettings $store,
        private readonly Translator $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('path', $this->router->url(...)),
            new TwigFunction('asset', $this->theme->asset(...)),
            new TwigFunction('theme_part', $this->themePart(...), ['is_safe' => ['html'], 'needs_context' => true]),
            new TwigFunction('csrf_token', $this->csrf->token(...)),
            new TwigFunction('csrf_field', $this->csrfField(...), ['is_safe' => ['html']]),
            new TwigFunction('flashes', $this->session->takeFlashes(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('money', fn (float|int|string|null $amount): string => \App\Support\Money::format((float) $amount, $this->store->currency(), $this->translator->intlLocale())),
        ];
    }

    public function getGlobals(): array
    {
        return [
            'store' => $this->store->toArray(),
            'theme_name' => $this->theme->name(),
        ];
    }

    /**
     * @param array<string, mixed> $context the calling view's variables
     * @param array<string, mixed> $vars    extra variables for the part
     */
    private function themePart(array $context, string $part, array $vars = []): string
    {
        $defaults = [
            'store'        => $this->store->toArray(),
            'theme'        => $this->theme->name(),
            'year'         => (int) date('Y'),
            'current_path' => $context['current_path'] ?? '/',
            'lang'         => $this->translator->htmlLang(),
            'languages'    => $this->languageLinks((string) ($context['current_path'] ?? '/')),
        ];
        // Only the built-in admin parts get the form token, never theme-provided parts.
        if (Theme::isAdminPart($part)) {
            $defaults['csrf_token'] = $this->csrf->token();
        }

        return $this->theme->render($part, $vars + $defaults);
    }

    /**
     * Links to the current page in each language (?lang= sets the visitor's choice).
     *
     * @return list<array{code: string, name: string, html_lang: string, url: string, active: bool}>
     */
    private function languageLinks(string $path): array
    {
        $links = [];
        foreach ($this->translator->languages() as $code => $name) {
            $links[] = [
                'code'      => $code,
                'name'      => $name,
                'html_lang' => $this->translator->htmlLang($code),
                'url'       => $path . '?lang=' . rawurlencode($code),
                'active'    => $code === $this->translator->locale(),
            ];
        }

        return $links;
    }

    private function csrfField(): string
    {
        return '<input type="hidden" name="' . Csrf::FIELD . '" value="' . htmlspecialchars($this->csrf->token()) . '">';
    }
}
