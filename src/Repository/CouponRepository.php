<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Discount codes (coupons table). Codes are stored upper-case; lookups
 * upper-case the input too, so they are case-insensitive. Rules (dates,
 * limits, amounts) live in CouponService.
 */
final class CouponRepository extends Repository
{
    public const TYPES = ['percent', 'fixed', 'free_shipping'];

    /** @return list<array<string, mixed>> newest first, with `order_count` (orders that used the code) */
    public function findAll(): array
    {
        return $this->all('
            SELECT c.*, (SELECT COUNT(*) FROM orders o WHERE o.coupon_code = c.code) AS order_count
            FROM coupons c
            ORDER BY c.is_active DESC, c.created_at DESC, c.id DESC
        ');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM coupons WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array
    {
        return $this->one('SELECT * FROM coupons WHERE code = :code', ['code' => mb_strtoupper(trim($code))]);
    }

    /**
     * Locks the coupon row until the surrounding transaction ends, so two
     * checkouts can't both take the last use.
     *
     * @return array<string, mixed>|null
     */
    public function lockByCode(string $code): ?array
    {
        return $this->one('SELECT * FROM coupons WHERE code = :code FOR UPDATE', ['code' => mb_strtoupper(trim($code))]);
    }

    /** @param array{code: string, type: string, value: float, min_subtotal: ?float, starts_at: ?string, ends_at: ?string, usage_limit: ?int, per_email_limit: ?int, is_active: int} $data */
    public function create(array $data): int
    {
        $this->run('
            INSERT INTO coupons (code, type, value, min_subtotal, starts_at, ends_at, usage_limit, per_email_limit, is_active)
            VALUES (:code, :type, :value, :min_subtotal, :starts_at, :ends_at, :usage_limit, :per_email_limit, :is_active)
        ', $data);

        return $this->lastId();
    }

    /** @param array{code: string, type: string, value: float, min_subtotal: ?float, starts_at: ?string, ends_at: ?string, usage_limit: ?int, per_email_limit: ?int, is_active: int} $data */
    public function update(int $id, array $data): void
    {
        $this->run('
            UPDATE coupons
            SET code = :code, type = :type, value = :value, min_subtotal = :min_subtotal, starts_at = :starts_at,
                ends_at = :ends_at, usage_limit = :usage_limit, per_email_limit = :per_email_limit, is_active = :is_active
            WHERE id = :id
        ', $data + ['id' => $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->run('UPDATE coupons SET is_active = :a WHERE id = :id', ['a' => $active ? 1 : 0, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->run('DELETE FROM coupons WHERE id = :id', ['id' => $id]);
    }

    /** True when another coupon already has this code. */
    public function codeTaken(string $code, int $exceptId = 0): bool
    {
        return (bool) $this->value(
            'SELECT COUNT(*) FROM coupons WHERE code = :code AND id <> :id',
            ['code' => mb_strtoupper(trim($code)), 'id' => $exceptId]
        );
    }

    /** Orders (ever) placed with this code. */
    public function orderCount(string $code): int
    {
        return (int) $this->value('SELECT COUNT(*) FROM orders WHERE coupon_code = :code', ['code' => mb_strtoupper(trim($code))]);
    }

    /** Orders by this email that currently count as a use of the code (not cancelled before payment). */
    public function usesByEmail(string $code, string $email): int
    {
        return (int) $this->value(
            'SELECT COUNT(*) FROM orders WHERE coupon_code = :code AND coupon_counted = 1 AND LOWER(email) = LOWER(:email)',
            ['code' => mb_strtoupper(trim($code)), 'email' => trim($email)]
        );
    }

    /** Call with the row locked (lockByCode()). */
    public function incrementUsed(int $id): void
    {
        $this->run('UPDATE coupons SET used_count = used_count + 1 WHERE id = :id', ['id' => $id]);
    }

    public function decrementUsed(string $code): void
    {
        $this->run('UPDATE coupons SET used_count = used_count - 1 WHERE code = :code AND used_count > 0', ['code' => mb_strtoupper(trim($code))]);
    }
}
