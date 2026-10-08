<?php

declare(strict_types=1);

namespace App\Repository;

use Generator;

/**
 * Lookups behind the JSON product export / import (the product with its
 * category, variants, translations and images in one document). Writes go
 * through ProductRepository / VariantRepository / CategoryRepository.
 */
final class ProductJsonRepository extends Repository
{
    /**
     * Every product with its category, filtered like the admin list.
     *
     * @param int|null $lowStockAtOrBelow only products with an active variant at or below this stock
     * @return Generator<int, array<string, mixed>>
     */
    public function exportProducts(?string $status, ?int $lowStockAtOrBelow = null): Generator
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
            SELECT p.id, p.name, p.short_description, p.long_description, p.image_path, p.images,
                   p.import_status, p.is_active, p.created_at, p.updated_at,
                   c.name AS category_name, c.slug AS category_slug, c.description AS category_description
            FROM products p
            JOIN categories c ON c.id = p.category_id
        ' . ($conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions)) . '
            ORDER BY p.id ASC
        ', $params);

        while (($row = $stmt->fetch()) !== false) {
            yield $row;
        }
    }

    /** @return array<string, mixed>|null an existing product with that name (case-insensitive) in that category */
    public function findByNameAndCategory(string $name, int $categoryId): ?array
    {
        return $this->one(
            'SELECT id, name, category_id FROM products WHERE name = :name AND category_id = :category_id ORDER BY id ASC LIMIT 1',
            ['name' => $name, 'category_id' => $categoryId]
        );
    }
}
