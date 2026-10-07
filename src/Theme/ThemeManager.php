<?php

declare(strict_types=1);

namespace App\Theme;

use App\Support\Paths;
use RuntimeException;
use ZipArchive;

/**
 * Lists, installs, removes and packages storefront themes
 * (public/themes/<slug>/). Used by Admin → Settings.
 *
 * Uploaded themes are zip files containing static files (CSS, images,
 * fonts, theme.json…) and Twig layout templates under layout/. PHP files
 * are never installed. Every template is compiled in Twig's sandbox before
 * the theme is installed, so a theme using anything outside the allowed
 * tags/filters/functions (see Theme::SANDBOX_*) is rejected up front.
 */
final class ThemeManager
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Theme $theme,
    ) {
    }

    public const BUILT_IN = ['default', 'minimal', 'warm', 'bold', 'neolab', 'succulent'];

    private const SLUG = '/^[a-z0-9][a-z0-9_-]{1,39}$/';
    public const MAX_ZIP_BYTES = 20 * 1024 * 1024;
    private const MAX_UNPACKED_BYTES = 50 * 1024 * 1024;
    private const MAX_FILES = 500;
    private const STATIC_EXTENSIONS = [
        'css', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'avif',
        'woff', 'woff2', 'ttf', 'otf', 'json', 'md', 'txt',
    ];
    private const MAX_TEMPLATE_BYTES = 200 * 1024;
    /** Script extensions rejected anywhere in a path, not just at the end. */
    private const EXECUTABLE_NAME = '/\.(php\d*|phtml|phar|pht|phps|pgif|inc|cgi|pl|py|sh|asp|aspx|jsp|shtml|htaccess|htpasswd|user\.ini)(\.|\/|$)/i';

    /** @return list<array{slug:string,name:string,description:string,author:string,version:string,screenshot:?string,builtin:bool}> */
    public function all(): array
    {
        $themes = [];
        foreach (glob($this->paths->themes() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $slug = basename($dir);
            if (!preg_match(self::SLUG, $slug)) {
                continue;
            }
            $meta = $this->readMeta($dir);
            $shot = is_file($dir . '/screenshot.png') ? 'screenshot.png' : (is_file($dir . '/screenshot.jpg') ? 'screenshot.jpg' : null);
            $themes[] = [
                'slug'        => $slug,
                'name'        => $meta['name'] ?? ucfirst($slug),
                'description' => $meta['description'] ?? '',
                'author'      => $meta['author'] ?? '',
                'version'     => $meta['version'] ?? '',
                'screenshot'  => $shot ? '/themes/' . $slug . '/' . $shot . '?v=' . filemtime($dir . '/' . $shot) : null,
                'builtin'     => in_array($slug, self::BUILT_IN, true),
            ];
        }

        // Built-ins first in their usual order, then uploaded themes by name.
        usort($themes, function (array $a, array $b) {
            $ia = array_search($a['slug'], self::BUILT_IN, true);
            $ib = array_search($b['slug'], self::BUILT_IN, true);
            if ($ia !== false || $ib !== false) {
                return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib);
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return $themes;
    }

    public function exists(string $slug): bool
    {
        return preg_match(self::SLUG, $slug) === 1 && is_dir($this->paths->themes() . '/' . $slug);
    }

    /**
     * Installs a theme from a zip file on disk (the controller moves the
     * upload there first).
     * Returns ['slug', 'name', 'skipped' => files left out because their type isn't allowed].
     *
     * @return array{slug: string, name: string, skipped: list<string>}
     */
    public function install(string $zipPath, bool $replace = false): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is not installed on this server.');
        }
        if (!is_file($zipPath) || filesize($zipPath) > self::MAX_ZIP_BYTES) {
            throw new RuntimeException('Upload failed — choose a .zip file (max ' . (self::MAX_ZIP_BYTES >> 20) . ' MB).');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('That file is not a valid .zip archive.');
        }

        try {
            if ($zip->numFiles > self::MAX_FILES) {
                throw new RuntimeException('The theme has too many files (max ' . self::MAX_FILES . ').');
            }

            // Accept theme.json at the zip root, or inside one top-level folder.
            $prefix = $this->findRoot($zip);
            $meta = json_decode((string) $zip->getFromName($prefix . 'theme.json'), true);
            if (!is_array($meta) || trim((string) ($meta['name'] ?? '')) === '') {
                throw new RuntimeException('theme.json is missing or has no "name". Download the blank theme to see the expected structure.');
            }

            $slug = $this->slugFor($meta, $prefix);
            if (in_array($slug, self::BUILT_IN, true)) {
                throw new RuntimeException("\"{$slug}\" is a built-in theme and can't be replaced. Give your theme a different \"slug\" in theme.json.");
            }
            if (is_dir($this->paths->themes() . '/' . $slug) && !$replace) {
                throw new RuntimeException("A theme called \"{$slug}\" is already installed. Tick \"Replace\" to update it.");
            }

            $entries = [];
            $skipped = [];
            $total = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) $stat['name'];
                if (str_ends_with($name, '/')) {
                    continue; // directory entry
                }
                if ($prefix !== '' && !str_starts_with($name, $prefix)) {
                    $skipped[] = $name;
                    continue;
                }
                $relative = substr($name, strlen($prefix));
                if (!$this->isSafePath($relative)) {
                    throw new RuntimeException("Unsafe file path in zip: {$name}");
                }
                if (str_starts_with(basename($relative), '.') || str_starts_with($relative, '__MACOSX/')) {
                    continue; // OS junk / dotfiles (.htaccess etc. are never installed)
                }

                // "x.php.css" can still run as PHP under some Apache setups
                // (AddHandler), so any executable extension anywhere is out.
                if (preg_match(self::EXECUTABLE_NAME, $relative)) {
                    $skipped[] = $relative;
                    continue;
                }
                // Admin parts always come from the default theme.
                if (preg_match('#^layout/admin-[^/]*$#', $relative)) {
                    $skipped[] = $relative;
                    continue;
                }

                $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
                $isTemplate = $ext === 'twig' && str_starts_with($relative, 'layout/');
                if (!$isTemplate && !in_array($ext, self::STATIC_EXTENSIONS, true)) {
                    $skipped[] = $relative; // includes any .php — themes never contain code
                    continue;
                }
                if ($isTemplate && (int) $stat['size'] > self::MAX_TEMPLATE_BYTES) {
                    throw new RuntimeException("Template {$relative} is too large (max " . (self::MAX_TEMPLATE_BYTES >> 10) . ' KB).');
                }

                $total += (int) $stat['size'];
                if ($total > self::MAX_UNPACKED_BYTES) {
                    throw new RuntimeException('The theme is too large once unpacked (max ' . (self::MAX_UNPACKED_BYTES >> 20) . ' MB).');
                }
                $entries[$i] = $relative;
            }

            if (!in_array('assets/css/style.css', $entries, true)) {
                throw new RuntimeException('The theme needs assets/css/style.css. Download the blank theme to see the expected structure.');
            }

            // Unpack into a staging folder first so a failure never leaves a half-installed theme.
            $staging = $this->paths->themes() . '/.upload-' . bin2hex(random_bytes(6));
            if (!@mkdir($staging, 0775) && !is_dir($staging)) {
                throw new RuntimeException('Could not write to public/themes — check that the web server can write there.');
            }

            try {
                foreach ($entries as $index => $relative) {
                    $target = $staging . '/' . $relative;
                    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true)) {
                        throw new RuntimeException('Could not create folders for ' . $relative);
                    }
                    $stream = $zip->getStream($zip->getNameIndex($index));
                    if ($stream === false || file_put_contents($target, $stream) === false) {
                        throw new RuntimeException('Could not extract ' . $relative);
                    }
                    fclose($stream);
                }

                // Compile every template in the sandbox before going live.
                if (is_dir($staging . '/layout')) {
                    $templateErrors = $this->theme->validateTemplates($staging . '/layout');
                    if ($templateErrors) {
                        throw new RuntimeException('The theme has template errors — ' . implode(' | ', array_slice($templateErrors, 0, 3)));
                    }
                }

                $final = $this->paths->themes() . '/' . $slug;
                if (is_dir($final)) {
                    $this->removeDir($final);
                }
                if (!rename($staging, $final)) {
                    throw new RuntimeException('Could not move the theme into place.');
                }
            } catch (\Throwable $e) {
                if (is_dir($staging)) {
                    $this->removeDir($staging);
                }
                throw $e;
            }

            return ['slug' => $slug, 'name' => (string) $meta['name'], 'skipped' => $skipped];
        } finally {
            $zip->close();
        }
    }

    public function delete(string $slug): void
    {
        if (in_array($slug, self::BUILT_IN, true)) {
            throw new RuntimeException('Built-in themes can\'t be deleted.');
        }
        if (!$this->exists($slug)) {
            throw new RuntimeException('That theme is not installed.');
        }
        $this->removeDir($this->paths->themes() . '/' . $slug);
    }

    /** Builds the downloadable starter theme and returns the zip's temp path. */
    public function blankThemeZip(): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is not installed on this server.');
        }

        $path = tempnam(sys_get_temp_dir(), 'theme') ?: throw new RuntimeException('No temp dir.');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $base = 'my-theme/';

        $zip->addFromString($base . 'theme.json', json_encode([
            'name'        => 'My Theme',
            'slug'        => 'my-theme',
            'description' => 'A short description shown in Admin → Settings.',
            'author'      => 'Your name',
            'version'     => '1.0.0',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $zip->addFromString($base . 'assets/css/style.css', $this->blankStylesheet());
        $zip->addFromString(
            $base . 'assets/images/icons/README.txt',
            "Optional: favicon.ico, favicon.svg, favicon-16x16.png, favicon-32x32.png, apple-touch-icon.png.\n"
            . "Any icon you leave out is taken from the default theme.\n"
        );
        $zip->addFromString($base . 'README.md', $this->blankReadme());

        // The default theme's templates, ready to edit.
        $layoutRoot = $this->paths->themes() . '/default/layout';
        foreach (glob($layoutRoot . '/{,*/}*.twig', GLOB_BRACE) ?: [] as $source) {
            if (str_starts_with(basename($source), 'admin-')) {
                continue; // the admin always uses the default theme's parts
            }
            $zip->addFile($source, $base . 'layout/' . substr($source, strlen($layoutRoot) + 1));
        }

        $zip->close();

        return $path;
    }

    // ------------------------------------------------------------ helpers

    /** @return array<string, mixed> */
    private function readMeta(string $dir): array
    {
        $meta = is_file($dir . '/theme.json') ? json_decode((string) file_get_contents($dir . '/theme.json'), true) : null;

        return is_array($meta) ? array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $meta) : [];
    }

    private function findRoot(ZipArchive $zip): string
    {
        if ($zip->locateName('theme.json') !== false) {
            return '';
        }
        $candidates = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^([^/]+)/theme\.json$#', $name, $m) && $m[1] !== '__MACOSX') {
                $candidates[] = $m[1] . '/';
            }
        }
        if (count($candidates) !== 1) {
            throw new RuntimeException('Couldn\'t find theme.json. Put it at the top of the zip (or in a single folder).');
        }

        return $candidates[0];
    }

    /** @param array<string, mixed> $meta */
    private function slugFor(array $meta, string $prefix): string
    {
        $raw = (string) ($meta['slug'] ?? ($prefix !== '' ? rtrim($prefix, '/') : $meta['name']));
        $slug = trim((string) preg_replace('/[^a-z0-9_-]+/', '-', strtolower($raw)), '-');
        if (!preg_match(self::SLUG, $slug)) {
            throw new RuntimeException('Invalid theme slug "' . $raw . '": use 2-40 lowercase letters, digits, - or _.');
        }

        return $slug;
    }

    private function isSafePath(string $path): bool
    {
        return $path !== ''
            && !str_contains($path, "\0")
            && !str_contains($path, '\\')
            && !str_starts_with($path, '/')
            && !preg_match('#(^|/)\.\.(/|$)#', $path)
            && !preg_match('#^[A-Za-z]:#', $path);
    }

    private function removeDir(string $dir): void
    {
        $real = realpath($dir);
        $root = realpath($this->paths->themes());
        if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Refusing to delete outside public/themes.');
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($real);
    }

    private function blankStylesheet(): string
    {
        return <<<'CSS'
/* My Theme
 *
 * This file is loaded instead of the default theme's stylesheet, so the
 * line below pulls the default styles in first — your rules then only
 * need to change what's different. Remove it to style everything yourself
 * (see public/themes/default/assets/css/style.css for every class used).
 */
@import url('../../../default/assets/css/style.css');

:root {
    /* Colours */
    --color-bg: #f6f6f4;            /* page background */
    --color-surface: #ffffff;       /* cards, panels, inputs */
    --color-surface-hover: #f1f1ee;
    --color-border: #e3e3de;
    --color-border-strong: #c4c4bc;
    --color-text: #1d1d1b;
    --color-muted: #6a6a64;         /* secondary text — keep 4.5:1 contrast */
    --color-accent: #2952cc;        /* buttons, links, highlights */
    --color-accent-soft: rgba(41, 82, 204, 0.08);
    --color-on-accent: #ffffff;     /* text on accent buttons */
    --color-danger: #c42b2b;

    /* Shape & type */
    --radius: 10px;
    --radius-sm: 8px;
    --font-body: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;

    /* Admin analytics charts */
    --chart-teal: #2952cc;
    --chart-violet: #d4760a;
}

/* Examples — uncomment and adapt:
.site-header { background: #111; }
.logo-text { color: #fff; }
.btn-primary { border-radius: 999px; }
.product-card { box-shadow: none; }
*/
CSS;
    }

    private function blankReadme(): string
    {
        $tags = implode(', ', Theme::SANDBOX_TAGS);
        $filters = implode(', ', Theme::SANDBOX_FILTERS);
        $functions = implode(', ', Theme::SANDBOX_FUNCTIONS);

        return <<<MD
# My Theme

Upload this folder as a .zip in **Admin → Settings → Themes**.

```
my-theme/
  theme.json                    name, slug (folder name: a-z 0-9 - _), description, author, version
  screenshot.png                optional preview shown in Settings (480×360 works well)
  assets/
    css/style.css               required — your styles
    images/                     optional — images your CSS uses: url('../images/…')
    images/icons/               optional favicons
    fonts/                      optional web fonts: url('../fonts/…')
  layout/                       optional Twig templates (https://twig.symfony.com):
    header.html.twig            <head>, site header and navigation
    footer.html.twig            site footer and scripts
    hero.html.twig              banner at the top of the shop page
    partials/*.html.twig       pieces you include, e.g. {% include 'partials/icons.html.twig' %}
```

Anything you leave out comes from the **default** theme, so the smallest
working theme is just `theme.json` + `assets/css/style.css`. Delete the
templates you don't change.

The admin panel uses your stylesheet, but its header and footer templates
always come from the default theme (admin-*.html.twig files are skipped on
upload), and admin pages never run theme scripts.

## Variables in templates

| Variable | Meaning |
|---|---|
| `page_title` | Title of the current page |
| `store.name`, `store.email`, `store.logo_url`, `store.currency` | Store settings (Admin → Settings) |
| `theme` | Active theme slug |
| `year` | Current year |
| `current_path` | Path of the current page, e.g. `/cart` |

Functions: `asset('css/style.css')` (URL of a file in your theme, or the default theme's
copy), `logo()` (logo image and/or store name, as set in Settings), and
`path('route.name')` for links — never hard-code URLs.

Routes you can link to: `home`, `cart`, `checkout`, `page.about`, `page.info`,
`page.support`, `page.terms`. A product link is `path('product.show', {id: 42})`;
query strings go in a third argument: `path('home', {}, {category: 'tools'})`.

## Sandbox

Templates run in Twig's sandbox — they can't run PHP or read files. Allowed:

- tags: {$tags}
- filters: {$filters}
- functions: {$functions}

Output is HTML-escaped automatically. Each template is checked when you
upload the theme; anything else is rejected with an error.

Allowed file types: twig (inside layout/), css, png, jpg, jpeg, gif, webp, avif,
svg, ico, woff, woff2, ttf, otf, json, md, txt.
MD;
    }
}
