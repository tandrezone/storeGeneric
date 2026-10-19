<?php

declare(strict_types=1);

namespace App\Repository;

/** Admin accounts (Admin → Users). Usernames are unique, compared case-insensitively. */
final class AdminUserRepository extends Repository
{
    private const COLUMNS = 'id, username, email, password_hash, role, active, last_login_at, created_at, locale, totp_secret, totp_enabled, totp_last_step';

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
            SELECT id, username, email, role, active, last_login_at, created_at, totp_enabled
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

    /**
     * Turns two-factor login on: stores the secret, the confirmed time step and fresh recovery-code hashes.
     *
     * @param list<string> $recoveryHashes
     */
    public function enableTotp(int $id, string $secret, int $step, array $recoveryHashes): void
    {
        $this->run('UPDATE admin_users SET totp_secret = :secret, totp_enabled = 1, totp_last_step = :step WHERE id = :id', [
            'id' => $id, 'secret' => $secret, 'step' => $step,
        ]);
        $this->replaceRecoveryCodes($id, $recoveryHashes);
    }

    /** Turns two-factor login off and forgets the secret and every recovery code. */
    public function clearTotp(int $id): void
    {
        $this->run('UPDATE admin_users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = NULL WHERE id = :id', ['id' => $id]);
        $this->replaceRecoveryCodes($id, []);
    }

    /**
     * Records $step as used, only when it is newer than the last accepted one
     * (atomic, so two parallel requests can't both use the same code).
     */
    public function claimTotpStep(int $id, int $step): bool
    {
        return $this->run(
            'UPDATE admin_users SET totp_last_step = :step WHERE id = :id AND (totp_last_step IS NULL OR totp_last_step < :step2)',
            ['id' => $id, 'step' => $step, 'step2' => $step]
        )->rowCount() === 1;
    }

    /** @param list<string> $hashes */
    public function replaceRecoveryCodes(int $id, array $hashes): void
    {
        $this->run('DELETE FROM admin_recovery_codes WHERE user_id = :id', ['id' => $id]);
        foreach ($hashes as $hash) {
            $this->run('INSERT INTO admin_recovery_codes (user_id, code_hash) VALUES (:id, :hash)', ['id' => $id, 'hash' => $hash]);
        }
    }

    /** Spends a recovery code; false when it doesn't exist or was used already. */
    public function consumeRecoveryCode(int $id, string $hash): bool
    {
        return $this->run(
            'UPDATE admin_recovery_codes SET used_at = NOW() WHERE user_id = :id AND code_hash = :hash AND used_at IS NULL LIMIT 1',
            ['id' => $id, 'hash' => $hash]
        )->rowCount() === 1;
    }

    public function countUnusedRecoveryCodes(int $id): int
    {
        return (int) $this->value('SELECT COUNT(*) FROM admin_recovery_codes WHERE user_id = :id AND used_at IS NULL', ['id' => $id]);
    }
}
