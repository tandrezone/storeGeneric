<?php

declare(strict_types=1);

namespace App\View;

use App\Security\AdminAuthenticator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Admin helpers for application views:
 *   admin_user()            the logged-in admin (id, username, email, role) or null
 *   admin_can('manager')    whether the admin has that role or a higher one
 */
final class AdminExtension extends AbstractExtension
{
    public function __construct(private readonly AdminAuthenticator $auth)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('admin_user', $this->auth->user(...)),
            new TwigFunction('admin_can', fn (string $role): bool => $this->auth->can($role)),
        ];
    }
}
