<?php

declare(strict_types=1);

namespace App\View;

use App\Http\Router;
use App\Http\Session;
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
 *   price|money                     "12.50€"
 */
final class ViewExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly Router $router,
        private readonly Theme $theme,
        private readonly Csrf $csrf,
        private readonly Session $session,
        private readonly StoreSettings $store,
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
            new TwigFilter('money', static fn (float|int|string|null $amount): string => number_format((float) $amount, 2) . '€'),
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
        return $this->theme->render($part, $vars + [
            'store'        => $this->store->toArray(),
            'theme'        => $this->theme->name(),
            'year'         => (int) date('Y'),
            'current_path' => $context['current_path'] ?? '/',
            'csrf_token'   => $this->csrf->token(),
        ]);
    }

    private function csrfField(): string
    {
        return '<input type="hidden" name="' . Csrf::FIELD . '" value="' . htmlspecialchars($this->csrf->token()) . '">';
    }
}
