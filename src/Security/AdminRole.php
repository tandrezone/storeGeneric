<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Admin roles, lowest first. Each role can do everything the roles below it can:
 *   staff    products, categories (no deletes) and orders
 *   manager  everything except users, settings and themes
 *   owner    everything
 */
enum AdminRole: string
{
    case Staff = 'staff';
    case Manager = 'manager';
    case Owner = 'owner';

    public function rank(): int
    {
        return match ($this) {
            self::Staff   => 1,
            self::Manager => 2,
            self::Owner   => 3,
        };
    }

    /** True when this role is $required or higher. */
    public function includes(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $r) => $r->value, self::cases());
    }
}
