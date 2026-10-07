<?php

declare(strict_types=1);

namespace App\Repository;

use Generator;

/**
 * Queries behind Admin → Products → Export / Import CSV (one row per
 * variant). Writes go through ProductRepository / VariantRepository /
 * CategoryRepository; only the lookups the import needs live here.
 */
final class ProductCsvRepository extends Repository
{
    /**
     * Every variant (and products without one), filtered like the admin list.
     *
     * @param int|null $lowStockAtOrBelow only products with an active variant at or below this stock
     * @return Generator<int, array<string, mixed>>
     */
    public function exportRows(?string $status, ?int $lowStockAtOrBelow = null): Generator
    {
        $conditions = [];
        $params = [];
        if ($status !== null) {
            $conditions[] = 'p.import_status = :status';
            $params['status'] = $status;
        }
        if ($lowStockAtOrBelow !== null) {
            $conditions[] = 'EXISTS (SELECT 1 FROM product_variants lv WHERE lv.product_id = p.id AND lv.is_active = 1 AND lv.stock <= :low_stock)';
            $params['low_stock'] = $lowStockAtOrBelow;
        }

        $stmt = $this->run('
            SELECT p.id AS product_id, p.name, c.name AS category, p.import_status AS status, p.short_description,
                   v.sku, v.label, v.unit, v.price, v.stock, v.is_active AS active
            FROM products p
            JOIN categories c ON c.id = p.category_id
            LEFT JOIN product_variants v ON v.product_id = p.id
        ' . ($conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions)) . '
            ORDER BY p.id ASC, v.id ASC
        ', $params);

        while (($row = $stmt->fetch()) !== false) {
            yield $row;
        }
    }

    /**
     * Variants with these SKUs, keyed by lower-cased SKU (the column is case-insensitive), with their product's fields.
     *
     * @param list<string> $skus
     * @return array<string, array<string, mixed>>
     */
    public function variantsBySku(array $skus): array
    {
        $found = [];
        foreach (array_chunk(array_values(array_unique($skus)), 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $rows = $this->all("
                SELECT v.id, v.product_id, v.sku, v.label, v.unit, v.price, v.stock, v.is_active,
                       p.name AS product_name, p.category_id, p.short_description, p.import_status
                FROM product_variants v
                JOIN products p ON p.id = v.product_id
                WHERE v.sku IN ({$placeholders})
            ", $chunk);
            foreach ($rows as $row) {
                $found[mb_strtolower((string) $row['sku'])] = $row;
            }
        }

        return $found;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>> id, name, category_id, short_description, import_status by id
     */
    public function productsById(array $ids): array
    {
        $found = [];
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            foreach ($this->all("SELECT id, name, category_id, short_description, import_status FROM products WHERE id IN ({$placeholders})", $chunk) as $row) {
                $found[(int) $row['id']] = $row;
            }
        }

        return $found;
    }

    /** @return array<string, int> lower-cased category name → id */
    public function categoryIdsByName(): array
    {
        $map = [];
        foreach ($this->all('SELECT id, name FROM categories ORDER BY id ASC') as $row) {
            $map[mb_strtolower(trim((string) $row['name']))] ??= (int) $row['id'];
        }

        return $map;
    }
}
