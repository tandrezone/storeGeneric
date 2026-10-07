<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Config;

/** Absolute URLs (canonical links, sitemap, social cards) based on APP_URL. */
final class SiteUrl
{
    public function __construct(private readonly Config $config)
    {
    }

    /** "https://shop.example" — APP_URL without a trailing slash. */
    public function base(): string
    {
        return rtrim($this->config->get('APP_URL', 'http://localhost'), '/');
    }

    /** Absolute URL for a site path ("/product/1-tea" or "assets/images/x.jpg"); absolute URLs pass through. */
    public function absolute(string $path): string
    {
        if (preg_match('~^https?://~i', $path)) {
            return $path;
        }

        return $this->base() . '/' . ltrim($path, '/');
    }
}
