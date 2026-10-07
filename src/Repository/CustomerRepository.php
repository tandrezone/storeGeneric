<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Customer accounts (storefront sign-in). Emails are stored lower-case, so
 * lookups are exact. Deleted accounts stay as anonymised rows so their
 * orders keep pointing at them.
 */
final class CustomerRepository extends Repository
{
    private const COLUMNS = 'id, email, name, password_hash, phone, active, email_verified_at, last_login_at, deleted_at, created_at, locale';

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM customers WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM customers WHERE email = :email', ['email' => self::normalizeEmail($email)]);
    }

    public function create(string $email, string $name, string $passwordHash, ?string $phone = null, ?string $locale = null): int
    {
        $this->run('
            INSERT INTO customers (email, name, password_hash, phone, locale)
            VALUES (:email, :name, :hash, :phone, :locale)
        ', ['email' => self::normalizeEmail($email), 'name' => $name, 'hash' => $passwordHash, 'phone' => $phone, 'locale' => $locale]);

        return $this->lastId();
    }

    public function updateProfile(int $id, string $name, ?string $phone): void
    {
        $this->run('UPDATE customers SET name = :name, phone = :phone WHERE id = :id', ['id' => $id, 'name' => $name, 'phone' => $phone]);
    }

    /** New email address: it has to be verified again. */
    public function changeEmail(int $id, string $email): void
    {
        $this->run('UPDATE customers SET email = :email, email_verified_at = NULL WHERE id = :id', [
            'id' => $id, 'email' => self::normalizeEmail($email),
        ]);
    }

    /** Preferred language (translations/<locale>.php) for emails and the storefront; null = store default. */
    public function setLocale(int $id, ?string $locale): void
    {
        $this->run('UPDATE customers SET locale = :locale WHERE id = :id', ['id' => $id, 'locale' => $locale]);
    }

    public function setPasswordHash(int $id, string $hash): void
    {
        $this->run('UPDATE customers SET password_hash = :hash WHERE id = :id', ['id' => $id, 'hash' => $hash]);
    }

    public function touchLastLogin(int $id): void
    {
        $this->run('UPDATE customers SET last_login_at = NOW() WHERE id = :id', ['id' => $id]);
    }

    /** Marks $email verified, if it is still the account's address. True when it was. */
    public function markVerified(int $id, string $email): bool
    {
        return $this->run(
            'UPDATE customers SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = :id AND email = :email AND deleted_at IS NULL',
            ['id' => $id, 'email' => self::normalizeEmail($email)]
        )->rowCount() === 1 || $this->isVerified($id, $email);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->run('UPDATE customers SET active = :active WHERE id = :id AND deleted_at IS NULL', ['id' => $id, 'active' => $active ? 1 : 0]);
    }

    /**
     * Deletes an account but keeps the row for its orders: personal data is
     * removed, the email freed for a new registration and sign-in disabled.
     * Saved addresses and tokens go with it.
     */
    public function anonymise(int $id): void
    {
        $this->run('DELETE FROM customer_addresses WHERE customer_id = :id', ['id' => $id]);
        $this->run('DELETE FROM customer_tokens WHERE customer_id = :id', ['id' => $id]);
        $this->run("
            UPDATE customers
            SET email = CONCAT('deleted-', id, '@invalid'), name = 'Deleted customer', password_hash = '',
                phone = NULL, active = 0, email_verified_at = NULL, deleted_at = NOW()
            WHERE id = :id
        ", ['id' => $id]);
    }

    /** @param array{q?: string, status?: ?string} $filters */
    public function countForAdmin(array $filters): int
    {
        [$where, $params] = $this->adminWhere($filters);

        return (int) $this->value('SELECT COUNT(*) FROM customers c' . $where, $params);
    }

    /**
     * One page of customers with their order count and total spent (paid
     * orders that weren't refunded), newest first.
     *
     * @param array{q?: string, status?: ?string} $filters
     * @return list<array<string, mixed>>
     */
    public function pageForAdmin(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->adminWhere($filters);

        return $this->all('
            SELECT c.id, c.email, c.name, c.phone, c.active, c.email_verified_at, c.last_login_at, c.deleted_at, c.created_at,
                   COUNT(o.id) AS order_count,
                   COALESCE(SUM(CASE WHEN o.payment_status = \'paid\' AND o.status <> \'refunded\' THEN o.total ELSE 0 END), 0) AS total_spent
            FROM customers c
            LEFT JOIN orders o ON o.customer_id = c.id'
            . $where . '
            GROUP BY c.id
            ORDER BY c.created_at DESC, c.id DESC' . sprintf(' LIMIT %d OFFSET %d', $limit, $offset), $params);
    }

    /** @return array{order_count: int, total_spent: float} */
    public function stats(int $id): array
    {
        $row = $this->one('
            SELECT COUNT(*) AS order_count,
                   COALESCE(SUM(CASE WHEN payment_status = \'paid\' AND status <> \'refunded\' THEN total ELSE 0 END), 0) AS total_spent
            FROM orders WHERE customer_id = :id
        ', ['id' => $id]) ?? [];

        return ['order_count' => (int) ($row['order_count'] ?? 0), 'total_spent' => (float) ($row['total_spent'] ?? 0)];
    }

    private function isVerified(int $id, string $email): bool
    {
        return (bool) $this->value(
            'SELECT COUNT(*) FROM customers WHERE id = :id AND email = :email AND email_verified_at IS NOT NULL',
            ['id' => $id, 'email' => self::normalizeEmail($email)]
        );
    }

    /**
     * @param array{q?: string, status?: ?string} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function adminWhere(array $filters): array
    {
        $where = [];
        $params = [];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(c.email LIKE :q1 OR c.name LIKE :q2 OR c.phone LIKE :q3)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like];
        }
        $where[] = match ($filters['status'] ?? null) {
            'active'   => 'c.active = 1 AND c.deleted_at IS NULL',
            'inactive' => 'c.active = 0 AND c.deleted_at IS NULL',
            'deleted'  => 'c.deleted_at IS NOT NULL',
            default    => '1 = 1',
        };

        return [' WHERE ' . implode(' AND ', $where), $params];
    }
}
