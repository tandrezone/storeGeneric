<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Password-reset and email-verification tokens. Only the SHA-256 of a
 * token is stored; expiry uses the database clock, and consume() marks a
 * token used atomically, so each one works once.
 */
final class CustomerTokenRepository extends Repository
{
    public const RESET = 'reset';
    public const VERIFY = 'verify';

    public function create(int $customerId, string $purpose, string $tokenHash, string $email, int $minutes): void
    {
        $this->run('
            INSERT INTO customer_tokens (customer_id, purpose, token_hash, email, expires_at)
            VALUES (:customer_id, :purpose, :hash, :email, NOW() + INTERVAL :minutes MINUTE)
        ', ['customer_id' => $customerId, 'purpose' => $purpose, 'hash' => $tokenHash, 'email' => $email, 'minutes' => $minutes]);
    }

    /** @return array<string, mixed>|null the unused, unexpired token row */
    public function findValid(string $tokenHash, string $purpose): ?array
    {
        return $this->one('
            SELECT * FROM customer_tokens
            WHERE token_hash = :hash AND purpose = :purpose AND used_at IS NULL AND expires_at > NOW()
        ', ['hash' => $tokenHash, 'purpose' => $purpose]);
    }

    /** Marks the token used. True only for the caller that did (single use under concurrency). */
    public function consume(int $id): bool
    {
        return $this->run(
            'UPDATE customer_tokens SET used_at = NOW() WHERE id = :id AND used_at IS NULL AND expires_at > NOW()',
            ['id' => $id]
        )->rowCount() === 1;
    }

    /** Invalidates every open token of that purpose (after a reset, or when a new link is sent). */
    public function invalidateAll(int $customerId, string $purpose): void
    {
        $this->run(
            'UPDATE customer_tokens SET used_at = NOW() WHERE customer_id = :c AND purpose = :purpose AND used_at IS NULL',
            ['c' => $customerId, 'purpose' => $purpose]
        );
        if (random_int(1, 20) === 1) {
            $this->run('DELETE FROM customer_tokens WHERE expires_at < (NOW() - INTERVAL 7 DAY)');
        }
    }
}
