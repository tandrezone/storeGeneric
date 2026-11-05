<?php

declare(strict_types=1);

namespace App\Repository;

/** Failed "Apply gift card" attempts per IP, so gift card codes can't be guessed at checkout. */
final class GiftCardAttemptRepository extends Repository
{
    public function countRecent(string $ip, int $minutes): int
    {
        return (int) $this->value(
            'SELECT COUNT(*) FROM gift_card_attempts WHERE ip_address = :ip AND created_at > (NOW() - INTERVAL :minutes MINUTE)',
            ['ip' => $ip, 'minutes' => $minutes]
        );
    }

    /** Logs a failed attempt and drops rows older than a day. */
    public function record(string $ip): void
    {
        $this->run('INSERT INTO gift_card_attempts (ip_address) VALUES (:ip)', ['ip' => $ip]);
        if (random_int(1, 50) === 1) {
            $this->run('DELETE FROM gift_card_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');
        }
    }
}
