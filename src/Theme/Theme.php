<?php

declare(strict_types=1);

namespace App\Theme;

use App\Http\Router;
use App\Service\StoreSettings;
use App\Support\Paths;
use Psr\Log\LoggerInterface;
use Throwable;
use Twig\Environment;
use Twig\Error\Error as TwigError;
use Twig\Extension\SandboxExtension;
use Twig\Loader\FilesystemLoader;
use Twig\Sandbox\SecurityPolicy;
use Twig\TwigFunction;

/**
 * The active storefront theme: renders its layout parts and resolves its
 * asset URLs.
 *
 * Themes live in public/themes/<slug>/:
 *   theme.json   name, description, author, version
 *   layout/      Twig parts: header, footer, hero, admin-header, admin-footer
 *                (*.html.twig) plus any partials they include
 *   assets/      css/style.css plus the fonts/images it uses
 *
 * A theme only ships what it changes — missing parts and assets come from
 * the "default" theme. Parts run in Twig's sandbox (only the SANDBOX_*
 * tags/filters/functions, no PHP), so uploaded themes can't run code; a
 * part that fails to render falls back to the default theme's copy.
 */
final class Theme
{
    public const DEFAULT = 'default';

    /** Parts a theme can override (file names without .html.twig). */
    public const PARTS = ['header', 'footer', 'hero', 'admin-header', 'admin-footer'];

    public const SANDBOX_TAGS = ['if', 'for', 'set', 'include', 'extends', 'block', 'embed', 'apply', 'with', 'verbatim', 'macro', 'import', 'from'];
    public const SANDBOX_FILTERS = [
        'escape', 'e', 'raw', 'upper', 'lower', 'title', 'capitalize', 'trim', 'default', 'length',
        'first', 'last', 'join', 'slice', 'split', 'replace', 'format', 'nl2br', 'striptags',
        'url_encode', 'number_format', 'date', 'abs', 'round', 'keys', 'merge', 'reverse', 'batch',
    ];
    public const SANDBOX_FUNCTIONS = ['asset', 'logo', 'path', 'include', 'block', 'parent', 'range', 'date', 'cycle'];

    private ?string $active = null;
    /** @var array<string, Environment> */
    private array $environments = [];

    public function __construct(
        private readonly Paths $paths,
        private readonly StoreSettings $store,
        private readonly Router $router,
        private readonly LoggerInterface $logger,
        private readonly string $cacheDir,
    ) {
    }

    /** Slug of the theme in use: the selected one if installed, else "default". */
    public function name(): string
    {
        if ($this->active === null) {
            $slug = $this->store->themeSlug();
            $this->active = preg_match('/^[a-z0-9_-]+$/i', $slug) && is_dir($this->paths->themes($slug))
                ? $slug
                : self::DEFAULT;
        }

        return $this->active;
    }

    /** Use another theme for the rest of this request (e.g. right after switching in Settings). */
    public function switchTo(string $slug): void
    {
        $this->active = preg_match('/^[a-z0-9_-]+$/i', $slug) && is_dir($this->paths->themes($slug)) ? $slug : null;
        $this->environments = [];
    }

    /** Public URL of a theme asset, from the active theme or the default one, cache-busted. */
    public function asset(string $path): string
    {
        $relative = 'assets/' . ltrim($path, '/');
        foreach (array_unique([$this->name(), self::DEFAULT]) as $theme) {
            $file = $this->paths->themes($theme . '/' . $relative);
            if (!str_contains($relative, '..') && is_file($file)) {
                return '/themes/' . rawurlencode($theme) . '/' . $relative . '?v=' . filemtime($file);
            }
        }

        return '/themes/' . $this->name() . '/' . $relative;
    }

    /** Header logo markup: the uploaded logo and/or the store name (escaped). */
    public function logo(): string
    {
        $name = htmlspecialchars($this->store->name());
        $url = $this->store->logoUrl();
        if ($url === null) {
            return '<span class="logo-text">' . $name . '</span>';
        }

        $html = '<img src="' . htmlspecialchars($url) . '" alt="' . $name . '" class="logo-img">';
        if ($this->store->showNameWithLogo()) {
            $html .= '<span class="logo-text">' . $name . '</span>';
        }

        return $html;
    }

    /**
     * Renders layout/<part>.html.twig from the active theme (falling back to
     * the default theme's copy if it's missing or fails).
     *
     * @param array<string, mixed> $context
     */
    public function render(string $part, array $context = []): string
    {
        $file = $part . '.html.twig';

        try {
            return $this->environment(false)->render($file, $context);
        } catch (TwigError $e) {
            if ($this->name() === self::DEFAULT) {
                throw $e;
            }
            $this->logger->error('Theme part failed, using the default theme instead', [
                'theme' => $this->name(),
                'part' => $file,
                'error' => $e->getMessage(),
            ]);

            return $this->environment(true)->render($file, $context);
        }
    }

    /**
     * Test-renders every template under $layoutDir in the sandbox, with the
     * default theme behind it as at runtime. Twig enforces the sandbox at
     * render time, so a compile alone isn't enough. Returns error messages.
     *
     * @return list<string>
     */
    public function validateTemplates(string $layoutDir): array
    {
        $errors = [];
        $twig = $this->buildEnvironment([$layoutDir, $this->paths->themes(self::DEFAULT . '/layout')], false);
        foreach (glob($layoutDir . '/{,*/}*.twig', GLOB_BRACE) ?: [] as $file) {
            $relative = substr($file, strlen($layoutDir) + 1);
            try {
                $twig->render($relative, $this->sampleContext());
            } catch (Throwable $e) {
                $errors[] = $relative . ': ' . $e->getMessage();
            }
        }

        return $errors;
    }

    /** @return array<string, mixed> realistic values for every variable a part can receive */
    private function sampleContext(): array
    {
        return [
            'page_title'   => 'Sample page',
            'active_nav'   => 'products',
            'admin_nav'    => [['key' => 'products', 'label' => 'Products', 'url' => '/admin/products', 'active' => true]],
            'csrf_token'   => 'sample-token',
            'store'        => ['name' => 'Sample Store', 'email' => 'shop@example.com', 'logo_url' => null, 'currency' => 'EUR'],
            'theme'        => 'sample',
            'year'         => (int) date('Y'),
            'current_path' => '/',
        ];
    }

    private function environment(bool $defaultOnly): Environment
    {
        $key = $defaultOnly ? '@default' : $this->name();
        if (!isset($this->environments[$key])) {
            $paths = [];
            $themeLayout = $this->paths->themes($this->name() . '/layout');
            if (!$defaultOnly && $this->name() !== self::DEFAULT && is_dir($themeLayout)) {
                $paths[] = $themeLayout;
            }
            $paths[] = $this->paths->themes(self::DEFAULT . '/layout');
            $this->environments[$key] = $this->buildEnvironment($paths, true);
        }

        return $this->environments[$key];
    }

    /** @param list<string> $paths */
    private function buildEnvironment(array $paths, bool $cache): Environment
    {
        $twig = new Environment(new FilesystemLoader($paths), [
            'cache'            => $cache ? $this->cacheDir : false,
            'auto_reload'      => true,
            'autoescape'       => 'html',
            'strict_variables' => false,
        ]);

        $twig->addFunction(new TwigFunction('asset', $this->asset(...)));
        $twig->addFunction(new TwigFunction('logo', $this->logo(...), ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('path', $this->router->url(...)));
        $twig->addExtension(new SandboxExtension(
            new SecurityPolicy(self::SANDBOX_TAGS, self::SANDBOX_FILTERS, [], [], self::SANDBOX_FUNCTIONS),
            true
        ));

        return $twig;
    }
}
