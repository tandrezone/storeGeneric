<?php

declare(strict_types=1);

namespace App\Http;

use App\Security\AdminRole;

/**
 * One entry in the route table: an HTTP method + path pattern mapped to a
 * controller method, plus per-route options used by middleware.
 */
final class Route
{
    private ?AdminRole $adminRole = null;
    /** @var array<string, AdminRole> form `action` value => lowest role allowed to post it */
    private array $actionRoles = [];
    private bool $csrf = true;
    private bool $tracked = false;
    private bool $audited = true;

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

    /**
     * Only for a logged-in admin with at least $role (AdminAuthMiddleware):
     * 'staff' < 'manager' < 'owner'. Calling it again replaces the role, so a
     * route inside an ->admin() group can lower or raise it.
     */
    public function admin(string $role = 'manager'): self
    {
        $this->adminRole = AdminRole::from($role);

        return $this;
    }

    /**
     * Form actions (the POSTed `action` field) that need a higher role than
     * the route itself, e.g. ->admin('staff')->actionsNeed('manager', ['delete']).
     *
     * @param list<string> $actions
     */
    public function actionsNeed(string $role, array $actions): self
    {
        foreach ($actions as $action) {
            $this->actionRoles[$action] = AdminRole::from($role);
        }

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

    /** Admin POSTs are written to the activity log; this opts out (read-only POST APIs). */
    public function withoutAudit(): self
    {
        $this->audited = false;

        return $this;
    }

    public function requiresAdmin(): bool
    {
        return $this->adminRole !== null;
    }

    /** Lowest role allowed on this route, or null for public routes. */
    public function adminRole(): ?AdminRole
    {
        return $this->adminRole;
    }

    /** Lowest role allowed to post $action (the route's own role when not restricted further). */
    public function roleForAction(string $action): ?AdminRole
    {
        return $this->actionRoles[$action] ?? $this->adminRole;
    }

    public function requiresCsrf(): bool
    {
        return $this->csrf;
    }

    public function isTracked(): bool
    {
        return $this->tracked;
    }

    public function isAudited(): bool
    {
        return $this->audited;
    }
}
