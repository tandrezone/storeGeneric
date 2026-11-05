<?php

declare(strict_types=1);

namespace App\Repository;

/** Saved products of signed-in customers (wishlist_items). */
final class WishlistRepository extends Repository
{
    public function has(int $customerId, int $productId): bool
    {
        return $this->value('SELECT 1 FROM wishlist_items WHERE customer_id = :c AND product_id = :p', ['c' => $customerId, 'p' => $productId]) !== false;
    }

    public function add(int $customerId, int $productId): void
    {
        $this->run('INSERT IGNORE INTO wishlist_items (customer_id, product_id) VALUES (:c, :p)', ['c' => $customerId, 'p' => $productId]);
    }

    public function remove(int $customerId, int $productId): void
    {
        $this->run('DELETE FROM wishlist_items WHERE customer_id = :c AND product_id = :p', ['c' => $customerId, 'p' => $productId]);
    }

    /** @return list<int> product ids, newest first */
    public function productIds(int $customerId): array
    {
        return array_map('intval', array_column($this->all('SELECT product_id FROM wishlist_items WHERE customer_id = :c ORDER BY id DESC', ['c' => $customerId]), 'product_id'));
    }

    public function count(int $customerId): int
    {
        return (int) $this->value('SELECT COUNT(*) FROM wishlist_items WHERE customer_id = :c', ['c' => $customerId]);
    }
}
