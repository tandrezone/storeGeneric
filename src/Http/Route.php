<?php

declare(strict_types=1);

namespace App\Http;

/**
 * One entry in the route table: an HTTP method + path pattern mapped to a
 * controller method, plus per-route options used by middleware.
 */
final class Route
{
    private bool $admin = false;
    private bool $csrf = true;
    private bool $tracked = false;

    /**
     * @param list<string>                $methods
     * @param array{0: class-string, 1: string} $handler
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $pattern,
        public readonly array $handler,
        public readonly ?string $name = null,
    ) {
    }

    /** Only for a logged-in admin (AdminAuthMiddleware). */
    public function admin(): self
    {
        $this->admin = true;

        return $this;
    }

    /** Skip the CSRF check — only for webhooks and other non-browser callers. */
    public function withoutCsrf(): self
    {
        $this->csrf = false;

        return $this;
    }

    /** Record a storefront visit for analytics (PageViewMiddleware). */
    public function tracked(): self
    {
        $this->tracked = true;

        return $this;
    }

    public function requiresAdmin(): bool
    {
        return $this->admin;
    }

    public function requiresCsrf(): bool
    {
        return $this->csrf;
    }

    public function isTracked(): bool
    {
        return $this->tracked;
    }
}
