<?php

declare(strict_types=1);

namespace App\Support;

/** Absolute locations of the project's main folders. */
final class Paths
{
    public function __construct(public readonly string $root)
    {
    }

    public function public(string $relative = ''): string
    {
        return $this->join($this->root . '/public', $relative);
    }

    public function templates(string $relative = ''): string
    {
        return $this->join($this->root . '/templates', $relative);
    }

    public function themes(string $relative = ''): string
    {
        return $this->join($this->root . '/public/themes', $relative);
    }

    public function var(string $relative = ''): string
    {
        return $this->join($this->root . '/var', $relative);
    }

    public function resources(string $relative = ''): string
    {
        return $this->join($this->root . '/resources', $relative);
    }

    private function join(string $base, string $relative): string
    {
        return $relative === '' ? $base : $base . '/' . ltrim($relative, '/');
    }
}
