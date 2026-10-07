<?php

declare(strict_types=1);

namespace App\Repository;

/** Admin accounts (Admin → Users). Usernames are unique, compared case-insensitively. */
final class AdminUserRepository extends Repository
{
    private const COLUMNS = 'id, username, email, password_hash, role, active, last_login_at, created_at, locale';

    public function count(): int
    {
        return (int) $this->value('SELECT COUNT(*) FROM admin_users');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM admin_users WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function findByUsername(string $username): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM admin_users WHERE username = :username', ['username' => $username]);
    }

    /** @return list<array<string, mixed>> without password hashes */
    public function findAll(): array
    {
        return $this->all("
            SELECT id, username, email, role, active, last_login_at, created_at
            FROM admin_users
            ORDER BY FIELD(role, 'owner', 'manager', 'staff'), username
        ");
    }

    public function countActiveOwners(): int
    {
        return (int) $this->value("SELECT COUNT(*) FROM admin_users WHERE role = 'owner' AND active = 1");
    }

    public function create(string $username, ?string $email, string $passwordHash, string $role): int
    {
        $this->run('
            INSERT INTO admin_users (username, email, password_hash, role)
            VALUES (:username, :email, :hash, :role)
        ', ['username' => $username, 'email' => $email, 'hash' => $passwordHash, 'role' => $role]);

        return $this->lastId();
    }

    public function update(int $id, ?string $email, string $role): void
    {
        $this->run('UPDATE admin_users SET email = :email, role = :role WHERE id = :id', [
            'id' => $id, 'email' => $email, 'role' => $role,
        ]);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->run('UPDATE admin_users SET active = :active WHERE id = :id', ['id' => $id, 'active' => (int) $active]);
    }

    public function setPasswordHash(int $id, string $hash): void
    {
        $this->run('UPDATE admin_users SET password_hash = :hash WHERE id = :id', ['id' => $id, 'hash' => $hash]);
    }

    /** Language of the admin panel for this user (translations/<locale>.php). */
    public function setLocale(int $id, string $locale): void
    {
        $this->run('UPDATE admin_users SET locale = :locale WHERE id = :id', ['id' => $id, 'locale' => $locale]);
    }

    public function touchLastLogin(int $id): void
    {
        $this->run('UPDATE admin_users SET last_login_at = NOW() WHERE id = :id', ['id' => $id]);
    }
}
