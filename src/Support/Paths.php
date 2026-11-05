<?php

declare(strict_types=1);

namespace App\Support;

/** Absolute locations of the project's main folders. */
final class Paths
{
    /**
     * @param string $root Store directory: .env, var/, public/ and any local overrides.
     * @param string $core Shared core directory (src/, vendor/, templates/, ...). Defaults to $root.
     */
    public function __construct(public readonly string $root, public readonly string $core = '')
    {
    }

    /** Core directory for a root: the root itself when it holds src/, otherwise its core/ link. */
    public static function detectCore(string $root): string
    {
        return is_dir($root . '/src') || !is_dir($root . '/core/src') ? $root : $root . '/core';
    }

    /** Absolute path of a project file: the store's own copy if present, else the core's. */
    public function locate(string $relative): string
    {
        $relative = ltrim($relative, '/');
        $own = $this->root . '/' . $relative;
        $core = ($this->core !== '' ? $this->core : $this->root) . '/' . $relative;

        return !file_exists($own) && file_exists($core) ? $core : $own;
    }

    public function public(string $relative = ''): string
    {
        return $this->join($this->root . '/public', $relative);
    }

    public function templates(string $relative = ''): string
    {
        return $this->join($this->locate('templates'), $relative);
    }

    public function themes(string $relative = ''): string
    {
        return $this->join($this->root . '/public/themes', $relative);
    }

    /** Shared themes: public/themes/global, normally a symlink to the core's public/themes. */
    public function themesGlobal(string $relative = ''): string
    {
        return $this->join($this->themes('global'), $relative);
    }

    /** Folder of a theme (+ optional relative path): the store's own copy first, else the global one. */
    public function themeDir(string $slug, string $relative = ''): string
    {
        return $this->join($this->isGlobalTheme($slug) ? $this->themesGlobal($slug) : $this->themes($slug), $relative);
    }

    /** True when the theme exists only in the global folder (a local theme with the same slug overrides it). */
    public function isGlobalTheme(string $slug): bool
    {
        return $slug !== 'global' && !is_dir($this->themes($slug)) && is_dir($this->themesGlobal($slug));
    }

    /** Public URL prefix of a theme's folder, without trailing slash. */
    public function themeUrl(string $slug): string
    {
        return '/themes/' . ($this->isGlobalTheme($slug) ? 'global/' : '') . rawurlencode($slug);
    }

    public function var(string $relative = ''): string
    {
        return $this->join($this->root . '/var', $relative);
    }

    public function resources(string $relative = ''): string
    {
        return $this->join($this->locate('resources'), $relative);
    }

    private function join(string $base, string $relative): string
    {
        return $relative === '' ? $base : $base . '/' . ltrim($relative, '/');
    }
}
