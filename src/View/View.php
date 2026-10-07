<?php

declare(strict_types=1);

namespace App\View;

use App\Support\Paths;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renders the application's own views (templates/*.html.twig). Unlike theme
 * parts these are trusted code, so they aren't sandboxed.
 */
final class View
{
    private Environment $twig;

    public function __construct(Paths $paths, ViewExtension $extension, string $cacheDir, bool $debug)
    {
        $this->twig = new Environment(new FilesystemLoader($paths->templates()), [
            'cache'            => $cacheDir,
            'auto_reload'      => true,
            'autoescape'       => 'html',
            'strict_variables' => $debug,
            'debug'            => $debug,
        ]);
        $this->twig->addExtension($extension);
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        return $this->twig->render($template, $data);
    }

    public function twig(): Environment
    {
        return $this->twig;
    }
}
