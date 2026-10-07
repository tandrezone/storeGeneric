<?php

declare(strict_types=1);

namespace App\Repository;

/** Failed admin logins per IP, for brute-force throttling. */
final class LoginAttemptRepository extends Repository
{
    public function countRecent(string $ip, int $minutes): int
    {
        return (int) $this->value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address = :ip AND created_at > (NOW() - INTERVAL :minutes MINUTE)',
            ['ip' => $ip, 'minutes' => $minutes]
        );
    }

    public function record(string $ip): void
    {
        $this->run('INSERT INTO login_attempts (ip_address) VALUES (:ip)', ['ip' => $ip]);
    }

    public function clear(string $ip): void
    {
        $this->run('DELETE FROM login_attempts WHERE ip_address = :ip', ['ip' => $ip]);
    }
}
