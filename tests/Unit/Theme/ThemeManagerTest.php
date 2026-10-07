<?php

declare(strict_types=1);

namespace Tests\Unit\Theme;

use App\Http\RouteCollection;
use App\Http\Router;
use App\I18n\Translator;
use App\Infrastructure\Database;
use App\Repository\SettingRepository;
use App\Service\StoreSettings;
use App\Support\Config;
use App\Support\Paths;
use App\Theme\Theme;
use App\Theme\ThemeManager;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/** Which files of an uploaded theme zip get installed. */
final class ThemeManagerTest extends TestCase
{
    private string $root;
    private ThemeManager $themes;

    public function setUp(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->skip('the zip extension is not loaded');
        }
        $this->root = sys_get_temp_dir() . '/store-theme-test-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/public/themes', 0777, true);

        $paths = new Paths($this->root);
        $config = Config::fromArray([]);
        $store = new StoreSettings(new SettingRepository(new Database($config)), $config, $paths);
        $theme = new Theme($paths, $store, new Router(new RouteCollection()), new NullLogger(), new Translator(dirname(__DIR__, 3) . '/translations'), $this->root . '/cache');
        $this->themes = new ThemeManager($paths, $theme);
    }

    public function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->root);
        }
    }

    public function testScriptsAndAdminPartsAreNeverInstalled(): void
    {
        $result = $this->themes->install($this->zip([
            'theme.json'                    => '{"name": "My Theme", "slug": "my-theme"}',
            'assets/css/style.css'          => 'body{}',
            'assets/img/logo.png'           => 'png',
            'assets/css/x.php.css'          => '<?php echo 1;',
            'shell.php'                     => '<?php',
            'assets/img/pic.phtml'          => 'x',
            'assets/run.sh'                 => 'x',
            'layout/admin-header.html.twig' => '<script>',
            '.htaccess'                     => 'AddHandler php .css',
            'notes.exe'                     => 'MZ',
        ]));

        $this->assertSame('my-theme', $result['slug']);
        $dir = $this->root . '/public/themes/my-theme';
        $this->assertTrue(is_file($dir . '/assets/css/style.css'));
        $this->assertTrue(is_file($dir . '/assets/img/logo.png'));
        foreach (['assets/css/x.php.css', 'shell.php', 'assets/img/pic.phtml', 'assets/run.sh', 'layout/admin-header.html.twig', '.htaccess', 'notes.exe'] as $file) {
            $this->assertFalse(file_exists($dir . '/' . $file), "{$file} must not be installed");
        }
        foreach (['assets/css/x.php.css', 'shell.php', 'layout/admin-header.html.twig', 'notes.exe'] as $file) {
            $this->assertContains($file, $result['skipped']);
        }
    }

    public function testRejectsPathTraversal(): void
    {
        $zip = $this->zip(['theme.json' => '{"name": "Evil", "slug": "evil"}', 'assets/css/style.css' => '', '../../escape.css' => 'x']);
        $this->assertThrows(RuntimeException::class, fn () => $this->themes->install($zip), 'Unsafe file path');
        $this->assertFalse(is_dir($this->root . '/public/themes/evil'));
    }

    public function testRejectsBuiltInSlugsAndMissingStylesheet(): void
    {
        $this->assertThrows(RuntimeException::class, fn () => $this->themes->install($this->zip(['theme.json' => '{"name": "x", "slug": "default"}', 'assets/css/style.css' => ''])), 'built-in');
        $this->assertThrows(RuntimeException::class, fn () => $this->themes->install($this->zip(['theme.json' => '{"name": "No CSS", "slug": "no-css"}'])), 'style.css');
    }

    /** @param array<string, string> $files */
    private function zip(array $files): string
    {
        $path = $this->root . '/upload-' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }
}
