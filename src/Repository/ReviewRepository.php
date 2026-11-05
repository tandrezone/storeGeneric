<?php

declare(strict_types=1);

namespace App\Repository;

/** Product reviews (product_reviews). Rules live in ReviewService. */
final class ReviewRepository extends Repository
{
    public const STATUSES = ['pending', 'approved', 'rejected'];

    /** @return list<array<string, mixed>> approved reviews, newest first */
    public function approvedFor(int $productId, int $limit = 20, int $offset = 0): array
    {
        return $this->all(
            "SELECT id, author, rating, title, body, verified, created_at FROM product_reviews WHERE product_id = :p AND status = 'approved' ORDER BY created_at DESC, id DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            ['p' => $productId]
        );
    }

    /** @return array{count: int, average: float, distribution: array<int, int>} over approved reviews */
    public function summary(int $productId): array
    {
        $rows = $this->all("SELECT rating, COUNT(*) AS n FROM product_reviews WHERE product_id = :p AND status = 'approved' GROUP BY rating", ['p' => $productId]);
        $distribution = array_fill_keys([5, 4, 3, 2, 1], 0);
        $count = 0;
        $sum = 0;
        foreach ($rows as $row) {
            $distribution[(int) $row['rating']] = (int) $row['n'];
            $count += (int) $row['n'];
            $sum += (int) $row['rating'] * (int) $row['n'];
        }

        return ['count' => $count, 'average' => $count > 0 ? round($sum / $count, 1) : 0.0, 'distribution' => $distribution];
    }

    /** @return array<string, mixed>|null a customer's own review of a product, whatever its status */
    public function findByCustomer(int $productId, int $customerId): ?array
    {
        return $this->one('SELECT * FROM product_reviews WHERE product_id = :p AND customer_id = :c', ['p' => $productId, 'c' => $customerId]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM product_reviews WHERE id = :id', ['id' => $id]);
    }

    /** Whether the customer has a paid order containing this product. */
    public function hasPurchased(int $customerId, int $productId): bool
    {
        return $this->value("
            SELECT 1 FROM orders o
            JOIN order_items oi ON oi.order_id = o.id
            JOIN product_variants v ON v.id = oi.variant_id
            WHERE o.customer_id = :c AND v.product_id = :p AND o.payment_status = 'paid' LIMIT 1
        ", ['c' => $customerId, 'p' => $productId]) !== false;
    }

    public function create(int $productId, ?int $customerId, string $author, int $rating, ?string $title, ?string $body, string $status, bool $verified): int
    {
        $this->run('
            INSERT INTO product_reviews (product_id, customer_id, author, rating, title, body, status, verified)
            VALUES (:p, :c, :a, :r, :t, :b, :s, :v)
        ', ['p' => $productId, 'c' => $customerId, 'a' => $author, 'r' => $rating, 't' => $title, 'b' => $body, 's' => $status, 'v' => $verified ? 1 : 0]);

        return $this->lastId();
    }

    public function setStatus(int $id, string $status): void
    {
        $this->run('UPDATE product_reviews SET status = :s WHERE id = :id', ['s' => $status, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->run('DELETE FROM product_reviews WHERE id = :id', ['id' => $id]);
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->value('SELECT COUNT(*) FROM product_reviews WHERE status = :s', ['s' => $status]);
    }

    public function countForAdmin(?string $status): int
    {
        return $status !== null ? $this->countByStatus($status) : (int) $this->value('SELECT COUNT(*) FROM product_reviews');
    }

    /** @return list<array<string, mixed>> newest first, with the product name */
    public function pageForAdmin(?string $status, int $limit, int $offset = 0): array
    {
        $where = $status !== null ? 'WHERE r.status = :s' : '';

        return $this->all(
            "SELECT r.*, p.name AS product_name FROM product_reviews r JOIN products p ON p.id = r.product_id {$where} ORDER BY r.status = 'pending' DESC, r.created_at DESC, r.id DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $status !== null ? ['s' => $status] : []
        );
    }

    /** Keeps the review but detaches it from a deleted account. */
    public function anonymiseCustomer(int $customerId): void
    {
        $this->run("UPDATE product_reviews SET customer_id = NULL, author = '' WHERE customer_id = :c", ['c' => $customerId]);
    }
}
