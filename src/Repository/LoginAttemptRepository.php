<?php

declare(strict_types=1);

namespace App\Repository;

/** Admin login attempts per IP and per username, for brute-force throttling. */
final class LoginAttemptRepository extends Repository
{
    public function countRecent(string $ip, int $minutes): int
    {
        return (int) $this->value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address = :ip AND created_at > (NOW() - INTERVAL :minutes MINUTE)',
            ['ip' => $ip, 'minutes' => $minutes]
        );
    }

    public function countRecentForUsername(string $username, int $minutes): int
    {
        return (int) $this->value(
            'SELECT COUNT(*) FROM login_attempts WHERE username = :username AND created_at > (NOW() - INTERVAL :minutes MINUTE)',
            ['username' => self::normalize($username), 'minutes' => $minutes]
        );
    }

    /** Recorded before the password is checked, so parallel guesses all count. */
    public function record(string $ip, string $username = ''): void
    {
        $this->run('INSERT INTO login_attempts (ip_address, username) VALUES (:ip, :username)', [
            'ip'       => substr($ip, 0, 45),
            'username' => $username === '' ? null : self::normalize($username),
        ]);
    }

    /** Forgets the attempts of an IP and of a username (after a successful login). */
    public function clear(string $ip, string $username = ''): void
    {
        $this->run('DELETE FROM login_attempts WHERE ip_address = :ip OR username = :username', [
            'ip'       => $ip,
            'username' => self::normalize($username),
        ]);
    }

    /** Forgets every attempt for $username (bin/console admin:unlock); returns how many. */
    public function clearUsername(string $username): int
    {
        return $this->run('DELETE FROM login_attempts WHERE username = :username', [
            'username' => self::normalize($username),
        ])->rowCount();
    }

    /** Forgets every attempt from $ip (bin/console admin:unlock --ip); returns how many. */
    public function clearIp(string $ip): int
    {
        return $this->run('DELETE FROM login_attempts WHERE ip_address = :ip', ['ip' => $ip])->rowCount();
    }

    private static function normalize(string $username): string
    {
        return mb_substr(mb_strtolower($username), 0, 190);
    }
}
