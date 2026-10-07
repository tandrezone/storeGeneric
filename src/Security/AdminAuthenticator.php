<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\Session;
use App\Repository\LoginAttemptRepository;
use App\Support\Config;
use RuntimeException;

/**
 * Session login for the admin area, backed by a single account from
 * .env (ADMIN_USERNAME / ADMIN_PASSWORD_HASH), with per-IP lockout after
 * repeated failures.
 */
final class AdminAuthenticator
{
    private const SESSION_KEY = 'admin_authenticated';
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;

    public function __construct(
        private readonly Session $session,
        private readonly LoginAttemptRepository $attempts,
        private readonly Config $config,
    ) {
    }

    public function isLoggedIn(): bool
    {
        return $this->session->get(self::SESSION_KEY) === true;
    }

    /**
     * Logs in on success. Returns false for bad credentials and for a
     * locked-out IP alike, so an attacker can't tell the two apart.
     */
    public function attempt(string $username, string $password, string $ip): bool
    {
        if ($this->attempts->countRecent($ip, self::LOCKOUT_MINUTES) >= self::MAX_ATTEMPTS) {
            return false;
        }

        $hash = $this->config->get('ADMIN_PASSWORD_HASH');
        if ($hash === '') {
            throw new RuntimeException('ADMIN_PASSWORD_HASH is not set.');
        }

        $valid = hash_equals($this->config->get('ADMIN_USERNAME', 'admin'), $username)
            && password_verify($password, $hash);

        if (!$valid) {
            $this->attempts->record($ip);

            return false;
        }

        $this->attempts->clear($ip);
        $this->session->regenerate();
        $this->session->set(self::SESSION_KEY, true);

        return true;
    }

    public function logout(): void
    {
        $this->session->destroy();
    }
}
