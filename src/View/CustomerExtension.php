<?php

declare(strict_types=1);

namespace App\View;

use App\Security\CustomerAuthenticator;
use PDOException;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Storefront account helper for application views:
 *   customer()   the signed-in customer (id, email, name, phone, email_verified_at, …) or null
 *
 * Used by the shop layout on every page, error pages included, so a
 * database failure here means "not signed in" rather than a second error.
 */
final class CustomerExtension extends AbstractExtension
{
    public function __construct(private readonly CustomerAuthenticator $auth)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('customer', $this->customer(...)),
        ];
    }

    /** @return array<string, mixed>|null */
    private function customer(): ?array
    {
        try {
            return $this->auth->customer();
        } catch (PDOException) {
            return null;
        }
    }
}
