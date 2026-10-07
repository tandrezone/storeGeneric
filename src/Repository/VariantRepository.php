<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Product variants (pack size / quantity + price). A variant priced at 0 or
 * less isn't for sale, so storefront lookups report its stock as 0.
 */
final class VariantRepository extends Repository
{
    /** @return array<string, mixed>|null an active variant with its product name */
    public function findAvailable(int $id): ?array
    {
        return $this->one('
            SELECT v.id, v.product_id, v.sku, v.label, v.unit, v.price,
                   IF(v.price <= 0, 0, v.stock) AS stock, p.name AS product_name
            FROM product_variants v
            JOIN products p ON p.id = v.product_id
            WHERE v.id = :id AND v.is_active = 1
        ', ['id' => $id]);
    }

    /** @return list<array<string, mixed>> active variants of a product (storefront) */
    public function findAvailableForProduct(int $productId): array
    {
        return $this->all('
            SELECT id, sku, label, unit, price, IF(price <= 0, 0, stock) AS stock
            FROM product_variants
            WHERE product_id = :product_id AND is_active = 1
            ORDER BY id ASC
        ', ['product_id' => $productId]);
    }

    /**
     * @param array<int, int> $productIds
     * @return array<int, list<array<string, mixed>>> all variants grouped by product id (admin)
     */
    public function findForProducts(array $productIds): array
    {
        $grouped = array_fill_keys($productIds, []);
        if ($productIds === []) {
            return $grouped;
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $rows = $this->all("
            SELECT id, product_id, sku, label, unit, price, stock, is_active
            FROM product_variants
            WHERE product_id IN ({$placeholders})
            ORDER BY product_id ASC, id ASC
        ", array_values($productIds));

        foreach ($rows as $row) {
            $grouped[(int) $row['product_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * Locks the variant row until the surrounding transaction ends.
     *
     * @return array{stock: int, price: string}|null
     */
    public function lockForUpdate(int $id): ?array
    {
        return $this->one('SELECT stock, price FROM product_variants WHERE id = :id FOR UPDATE', ['id' => $id]);
    }

    /**
     * @param array{product_id: int, sku: string, label: ?string, unit: ?string, price: float, stock: int, is_active?: int} $data
     * @throws \PDOException when the SKU is already used by another variant
     */
    public function create(array $data): int
    {
        $this->run('
            INSERT INTO product_variants (product_id, sku, label, unit, price, stock, is_active)
            VALUES (:product_id, :sku, :label, :unit, :price, :stock, :is_active)
        ', $data + ['is_active' => 1]);

        return $this->lastId();
    }

    /**
     * @param array{sku: string, label: ?string, unit: ?string, price: float, stock: int, is_active: int} $data
     * @throws \PDOException when the SKU is already used by another variant
     */
    public function update(int $id, int $productId, array $data): void
    {
        $this->run('
            UPDATE product_variants
            SET sku = :sku, label = :label, unit = :unit, price = :price, stock = :stock, is_active = :is_active
            WHERE id = :id AND product_id = :product_id
        ', $data + ['id' => $id, 'product_id' => $productId]);
    }

    public function decrementStock(int $id, int $quantity): void
    {
        $this->run('UPDATE product_variants SET stock = GREATEST(stock - :qty, 0) WHERE id = :id', ['qty' => $quantity, 'id' => $id]);
    }
}
