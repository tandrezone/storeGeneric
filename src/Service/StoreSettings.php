<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\SettingRepository;
use App\Support\Config;
use App\Support\Paths;

/**
 * The store's identity and presentation. Values saved in Admin → Settings
 * win; otherwise the .env value is used, then a built-in default.
 */
final class StoreSettings
{
    public const LOGO_DIR = 'assets/images/branding';

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly Config $config,
        private readonly Paths $paths,
    ) {
    }

    public function name(): string
    {
        return $this->settings->get('store_name') ?? $this->config->get('STORE_NAME', 'My Store');
    }

    public function email(): string
    {
        return $this->settings->get('store_email') ?? $this->config->get('STORE_EMAIL', 'support@example.com');
    }

    /** Email saved in Settings only ('' when the .env/default value is in use). */
    public function savedEmail(): string
    {
        return $this->settings->get('store_email') ?? '';
    }

    public function currency(): string
    {
        return strtoupper($this->config->get('STORE_CURRENCY', 'EUR'));
    }

    /** Slug of the selected theme (not validated — see Theme::name()). */
    public function themeSlug(): string
    {
        return $this->settings->get('theme') ?? $this->config->get('THEME', 'default');
    }

    public function logoPath(): ?string
    {
        $path = $this->settings->get('logo_path');

        return $path !== null && is_file($this->paths->public($path)) ? $path : null;
    }

    /** Public URL of the uploaded logo (cache-busted), or null. */
    public function logoUrl(): ?string
    {
        $path = $this->logoPath();

        return $path === null ? null : '/' . $path . '?v=' . filemtime($this->paths->public($path));
    }

    public function showNameWithLogo(): bool
    {
        return $this->settings->get('logo_show_name', '1') === '1';
    }

    /** @return array{name: string, email: string, logo_url: ?string, currency: string} */
    public function toArray(): array
    {
        return [
            'name'     => $this->name(),
            'email'    => $this->email(),
            'logo_url' => $this->logoUrl(),
            'currency' => $this->currency(),
        ];
    }
}
