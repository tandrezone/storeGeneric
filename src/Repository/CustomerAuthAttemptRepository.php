<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Customer sign-ins, password-reset requests and registrations per IP and
 * per email, for throttling (kept apart from the admin login_attempts).
 */
final class CustomerAuthAttemptRepository extends Repository
{
    public const LOGIN = 'login';
    public const RESET = 'reset';
    public const REGISTER = 'register';

    public function countRecentForIp(string $kind, string $ip, int $minutes): int
    {
        return (int) $this->value(
            'SELECT COUNT(*) FROM customer_auth_attempts WHERE kind = :kind AND ip_address = :ip AND created_at > (NOW() - INTERVAL :minutes MINUTE)',
            ['kind' => $kind, 'ip' => substr($ip, 0, 45), 'minutes' => $minutes]
        );
    }

    public function countRecentForEmail(string $kind, string $email, int $minutes): int
    {
        return (int) $this->value(
            'SELECT COUNT(*) FROM customer_auth_attempts WHERE kind = :kind AND email = :email AND created_at > (NOW() - INTERVAL :minutes MINUTE)',
            ['kind' => $kind, 'email' => self::normalize($email), 'minutes' => $minutes]
        );
    }

    /** Recorded before the password is checked, so parallel guesses all count. */
    public function record(string $kind, string $ip, string $email = ''): void
    {
        $this->run('INSERT INTO customer_auth_attempts (kind, ip_address, email) VALUES (:kind, :ip, :email)', [
            'kind'  => $kind,
            'ip'    => substr($ip, 0, 45),
            'email' => $email === '' ? null : self::normalize($email),
        ]);
        if (random_int(1, 50) === 1) {
            $this->run('DELETE FROM customer_auth_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');
        }
    }

    /**
     * Forgets the failed sign-ins for an email (after a successful one). The
     * IP's count stays: signing in to your own account mustn't reset the
     * budget for guessing other people's passwords.
     */
    public function clearEmail(string $kind, string $email): void
    {
        $this->run('DELETE FROM customer_auth_attempts WHERE kind = :kind AND email = :email', [
            'kind'  => $kind,
            'email' => self::normalize($email),
        ]);
    }

    private static function normalize(string $email): string
    {
        return mb_substr(mb_strtolower(trim($email)), 0, 190);
    }
}
