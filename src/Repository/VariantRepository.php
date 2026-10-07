<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Product variants (pack size / quantity + price). A variant priced at 0 or
 * less isn't for sale, so storefront lookups report its stock as 0.
 */
final class VariantRepository extends Repository
{
    /** @return array<string, mixed>|null an active variant of a visible product, with the product name */
    public function findAvailable(int $id): ?array
    {
        return $this->one('
            SELECT v.id, v.product_id, v.sku, v.label, v.unit, v.price,
                   IF(v.price <= 0, 0, v.stock) AS stock, p.name AS product_name
            FROM product_variants v
            JOIN products p ON p.id = v.product_id
            WHERE v.id = :id AND v.is_active = 1 AND p.is_active = 1 AND p.import_status = \'approved\'
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
     * Locks the variant row (and its product's) until the surrounding transaction
     * ends. `available` is 0 when the variant or its product is hidden.
     *
     * @return array{stock: int, price: string, label: ?string, unit: ?string, product_name: string, available: int}|null
     */
    public function lockForUpdate(int $id): ?array
    {
        return $this->one('
            SELECT v.stock, v.price, v.label, v.unit, p.name AS product_name,
                   (v.is_active = 1 AND p.is_active = 1 AND p.import_status = \'approved\') AS available
            FROM product_variants v
            JOIN products p ON p.id = v.product_id
            WHERE v.id = :id
            FOR UPDATE
        ', ['id' => $id]);
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
     * Returns false when the product has no such variant. (MySQL reports 0
     * affected rows for an unchanged row too, hence the existence check.)
     *
     * @throws \PDOException when the SKU is already used by another variant
     */
    public function update(int $id, int $productId, array $data): bool
    {
        $exists = $this->value(
            'SELECT 1 FROM product_variants WHERE id = :id AND product_id = :product_id',
            ['id' => $id, 'product_id' => $productId]
        );
        if ($exists === false) {
            return false;
        }

        $this->run('
            UPDATE product_variants
            SET sku = :sku, label = :label, unit = :unit, price = :price, stock = :stock, is_active = :is_active
            WHERE id = :id AND product_id = :product_id
        ', $data + ['id' => $id, 'product_id' => $productId]);

        return true;
    }

    /**
     * Deletes one of a product's variants (cart lines holding it go with it).
     *
     * @throws \PDOException when existing orders still reference the variant
     */
    public function delete(int $id, int $productId): bool
    {
        return $this->run('DELETE FROM product_variants WHERE id = :id AND product_id = :product_id', ['id' => $id, 'product_id' => $productId])
            ->rowCount() > 0;
    }

    /** Takes stock (never below 0). Checkout locks and checks the row first, so it only floors for late payments. */
    public function decrementStock(int $id, int $quantity): void
    {
        $this->run('UPDATE product_variants SET stock = GREATEST(CAST(stock AS SIGNED) - :qty, 0) WHERE id = :id', ['qty' => $quantity, 'id' => $id]);
    }

    /** Gives stock back (cancelled, expired or refunded orders). */
    public function incrementStock(int $id, int $quantity): void
    {
        $this->run('UPDATE product_variants SET stock = stock + :qty WHERE id = :id', ['qty' => $quantity, 'id' => $id]);
    }

    // ---- admin: bulk prices and low stock ----------------------------------

    /** Largest value DECIMAL(10,2) can hold. */
    public const MAX_PRICE = 99999999.99;
    /** Bulk price modes: set to a value, or change by a percentage / fixed amount. */
    public const PRICE_MODES = ['set', 'increase_percent', 'decrease_percent', 'increase_amount', 'decrease_amount'];

    /**
     * Changes the price of every variant of the given products. Results are
     * rounded to 2 decimals and kept between 0 and MAX_PRICE. Returns the
     * number of variants whose price changed.
     *
     * @param list<int> $productIds
     */
    public function adjustPricesForProducts(array $productIds, string $mode, float $value): int
    {
        if ($productIds === [] || !in_array($mode, self::PRICE_MODES, true) || $value < 0) {
            return 0;
        }

        $expression = match ($mode) {
            'set'              => '?',
            'increase_percent' => 'price * (1 + ? / 100)',
            'decrease_percent' => 'price * (1 - ? / 100)',
            'increase_amount'  => 'price + ?',
            'decrease_amount'  => 'price - ?',
        };
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));

        return $this->run(
            "UPDATE product_variants
             SET price = LEAST(GREATEST(ROUND({$expression}, 2), 0), ?)
             WHERE product_id IN ({$placeholders})",
            [$value, self::MAX_PRICE, ...$productIds]
        )->rowCount();
    }

    /** Active variants with stock at or below $threshold. */
    public function countLowStock(int $threshold): int
    {
        return (int) $this->value(
            'SELECT COUNT(*) FROM product_variants WHERE is_active = 1 AND stock <= :threshold',
            ['threshold' => $threshold]
        );
    }

    /**
     * Active variants with stock at or below $threshold, lowest stock first,
     * with their product's name and status (admin dashboard / alerts).
     *
     * @return list<array<string, mixed>>
     */
    public function findLowStock(int $threshold, int $limit = 50): array
    {
        return $this->all(sprintf('
            SELECT v.id, v.product_id, v.sku, v.label, v.unit, v.price, v.stock,
                   p.name AS product_name, p.import_status
            FROM product_variants v
            JOIN products p ON p.id = v.product_id
            WHERE v.is_active = 1 AND v.stock <= :threshold
            ORDER BY v.stock ASC, p.name ASC, v.id ASC
            LIMIT %d
        ', max(1, $limit)), ['threshold' => $threshold]);
    }
}
