<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Http\RouteCollection;
use App\Http\Router;
use App\I18n\Translator;
use App\Infrastructure\Database;
use App\Repository\SettingRepository;
use App\Service\PlaceholderImage;
use App\Service\StoreSettings;
use App\Support\Config;
use App\Support\Paths;
use App\Theme\Theme;
use Psr\Log\NullLogger;
use Tests\TestCase;

/** Theme-styled "no photo" cards with the product name. */
final class PlaceholderImageTest extends TestCase
{
    private string $root;
    private string $core;
    private Theme $theme;
    private PlaceholderImage $placeholders;

    public function setUp(): void
    {
        if (!function_exists('imagettftext')) {
            $this->skip('GD with FreeType is not available');
        }
        $this->core = dirname(__DIR__, 3);
        $this->root = sys_get_temp_dir() . '/store-placeholder-test-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/public/themes', 0777, true);
        symlink($this->core . '/public/themes', $this->root . '/public/themes/global');

        $paths = new Paths($this->root, $this->core);
        $config = Config::fromArray([]);
        $store = new StoreSettings(new SettingRepository(new Database($config)), $config, $paths);
        $this->theme = new Theme($paths, $store, new Router(new RouteCollection()), new NullLogger(), new Translator($this->core . '/translations'), $this->root . '/cache');
        $this->placeholders = new PlaceholderImage($paths, $this->theme);
    }

    public function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    /** @return list<string> */
    private function builtInThemes(): array
    {
        return array_map('basename', glob($this->core . '/public/themes/*', GLOB_ONLYDIR) ?: []);
    }

    public function testEveryBuiltInThemeHasAValidPlaceholderStyle(): void
    {
        foreach ($this->builtInThemes() as $slug) {
            $manifest = json_decode((string) file_get_contents($this->core . "/public/themes/$slug/theme.json"), true);
            $style = $manifest['placeholder'] ?? null;
            $this->assertTrue(is_array($style), "$slug has a placeholder block");
            $this->assertTrue(isset(PlaceholderImage::FONTS[$style['font']]), "$slug: known font {$style['font']}");
            $this->assertTrue(is_file($this->core . '/resources/fonts/' . PlaceholderImage::FONTS[$style['font']]), "$slug: font file is bundled");
            $this->assertContains($style['motif'], PlaceholderImage::MOTIFS, "$slug: known motif");
            foreach (['text', 'accent', 'accent2', ...$style['background']] as $color) {
                $value = $style[$color] ?? $color;
                $this->assertTrue((bool) preg_match('/^#[0-9a-f]{6}$/i', $value), "$slug: $value is a #rrggbb colour");
            }
        }
    }

    public function testEveryThemeRendersACardAndSwitchingThemeReplacesIt(): void
    {
        $product = ['id' => 42, 'name' => 'Echeveria Blue Dragon'];
        $previous = null;
        foreach ($this->builtInThemes() as $slug) {
            $this->theme->switchTo($slug);
            $path = $this->placeholders->pathFor($product);
            $file = $this->root . '/public/' . $path;

            $this->assertTrue(is_file($file), "$slug card was written");
            [$width, $height, $type] = getimagesize($file);
            $this->assertSame([800, 600, IMAGETYPE_JPEG], [$width, $height, $type], "$slug card size/format");
            $this->assertSame($path, $this->placeholders->cachedPathFor($product), "$slug card is reused");
            if ($previous !== null) {
                $this->assertNotSame($previous, $path, "$slug gets its own card");
                $this->assertFalse(is_file($this->root . '/public/' . $previous), 'the previous theme\'s card is removed');
            }
            $previous = $path;
        }
    }

    public function testRenamingAProductDrawsANewCard(): void
    {
        $this->theme->switchTo('candy');
        $first = $this->placeholders->pathFor(['id' => 7, 'name' => 'Old name']);
        $second = $this->placeholders->pathFor(['id' => 7, 'name' => 'New name']);
        $this->assertNotSame($first, $second);
        $this->assertSame('', $this->placeholders->cachedPathFor(['id' => 7, 'name' => 'Old name']));
    }

    public function testThemesWithoutAPlaceholderBlockUseTheirCssColours(): void
    {
        $dir = $this->root . '/public/themes/custom';
        mkdir($dir . '/assets/css', 0777, true);
        file_put_contents($dir . '/theme.json', '{"name": "Custom"}');
        file_put_contents($dir . '/assets/css/style.css', ":root {\n  --color-bg: #101010; /* night */\n  --color-surface: #202020;\n  --color-text: #fafafa;\n  --color-accent: #ff0066;\n  --font-theme: Georgia, serif;\n}\nbody { color: red; }");
        $this->theme->switchTo('custom');

        $style = $this->placeholders->style();
        $this->assertSame(['#202020', '#101010'], $style['background']);
        $this->assertSame('#fafafa', $style['text']);
        $this->assertSame('#ff0066', $style['accent']);
        $this->assertSame('serif', $style['font']);
        $this->assertSame('corners', $style['motif']);
        $this->assertTrue(is_file($this->root . '/public/' . $this->placeholders->pathFor(['id' => 1, 'name' => 'Lamp'])));
    }
}
