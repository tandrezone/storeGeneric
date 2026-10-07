<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Products. Storefront lookups only return active products with
 * import_status = 'approved'; the *ForAdmin methods return everything.
 */
final class ProductRepository extends Repository
{
    public const STATUSES = ['created', 'invalid', 'update', 'approved'];

    /** Admin table columns that can be sorted, mapped to SQL. */
    public const ADMIN_SORT_COLUMNS = [
        'name'     => 'p.name',
        'category' => 'c.name',
        'variants' => 'variant_count',
        // ENUM columns sort by declaration order; CAST sorts alphabetically.
        'status'   => 'CAST(p.import_status AS CHAR)',
    ];

    /**
     * Lowest price a product is for sale at. Variants priced at 0 or less
     * aren't for sale (see VariantRepository), so they count for neither
     * from_price nor stock.
     */
    private const FROM_PRICE_SQL = 'MIN(CASE WHEN v.price > 0 THEN v.price END)';

    /**
     * Storefront sort options (?sort=…), mapped to SQL. Products without a
     * price sort last. (MariaDB can't use an aggregate's alias inside an
     * ORDER BY expression, hence the repeated aggregate.)
     */
    public const SHOP_SORTS = [
        'name'       => 'p.name ASC, p.id ASC',
        'price_asc'  => self::FROM_PRICE_SQL . ' IS NULL, from_price ASC, p.name ASC',
        'price_desc' => self::FROM_PRICE_SQL . ' IS NULL, from_price DESC, p.name ASC',
        'newest'     => 'p.created_at DESC, p.id DESC',
    ];

    /** Columns for storefront product cards. */
    private const LISTING_SELECT = "
        SELECT p.id, p.name, p.short_description, p.image_path, p.created_at,
               c.name AS category_name, c.slug AS category_slug,
               " . self::FROM_PRICE_SQL . " AS from_price,
               COALESCE(SUM(CASE WHEN v.price > 0 THEN GREATEST(v.stock, 0) END), 0) AS stock
        FROM products p
        JOIN categories c ON c.id = p.category_id
        LEFT JOIN product_variants v ON v.product_id = p.id AND v.is_active = 1
    ";

    /**
     * One page of visible products with their lowest price and total stock,
     * filtered by category slug and a search over name, short description,
     * category name and variant SKU (every word must match).
     *
     * @return list<array<string, mixed>>
     */
    public function findVisible(
        ?string $categorySlug = null,
        string $search = '',
        string $sort = 'name',
        ?int $limit = null,
        int $offset = 0,
    ): array {
        [$where, $params] = $this->visibleFilter($categorySlug, $search);
        $sql = self::LISTING_SELECT . $where . ' GROUP BY p.id ORDER BY ' . (self::SHOP_SORTS[$sort] ?? self::SHOP_SORTS['name']);
        if ($limit !== null) {
            $sql .= sprintf(' LIMIT %d OFFSET %d', max(1, $limit), max(0, $offset));
        }

        return $this->all($sql, $params);
    }

    /** Number of products findVisible() would return without a limit. */
    public function countVisible(?string $categorySlug = null, string $search = ''): int
    {
        [$where, $params] = $this->visibleFilter($categorySlug, $search);

        return (int) $this->value('SELECT COUNT(*) FROM products p JOIN categories c ON c.id = p.category_id' . $where, $params);
    }

    /** @return list<array<string, mixed>> other visible products in the same category (product page) */
    public function findRelated(int $productId, int $categoryId, int $limit = 4): array
    {
        return $this->all(
            self::LISTING_SELECT . "
            WHERE p.is_active = 1 AND p.import_status = 'approved'
              AND p.category_id = :category_id AND p.id <> :id
            GROUP BY p.id
            ORDER BY p.created_at DESC, p.id DESC
            LIMIT " . max(1, $limit),
            ['category_id' => $categoryId, 'id' => $productId]
        );
    }

    /** @return list<array<string, mixed>> id, name, updated_at of every visible product (sitemap.xml) */
    public function findVisibleForSitemap(): array
    {
        return $this->all("
            SELECT id, name, updated_at
            FROM products
            WHERE is_active = 1 AND import_status = 'approved'
            ORDER BY id ASC
        ");
    }

    /** @return array{0: string, 1: array<string, string>} WHERE clause and parameters for storefront listings */
    private function visibleFilter(?string $categorySlug, string $search): array
    {
        $where = " WHERE p.is_active = 1 AND p.import_status = 'approved'";
        $params = [];
        if ($categorySlug !== null && $categorySlug !== '') {
            $where .= ' AND c.slug = :slug';
            $params['slug'] = $categorySlug;
        }

        $words = array_slice(array_unique(preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: []), 0, 5);
        foreach ($words as $i => $word) {
            $like = '%' . addcslashes($word, '%_\\') . '%';
            // Native prepares: every placeholder needs its own name.
            $where .= " AND (p.name LIKE :q{$i}a OR p.short_description LIKE :q{$i}b OR c.name LIKE :q{$i}c
                OR EXISTS (SELECT 1 FROM product_variants sv
                           WHERE sv.product_id = p.id AND sv.is_active = 1 AND sv.sku LIKE :q{$i}d))";
            foreach (['a', 'b', 'c', 'd'] as $suffix) {
                $params["q{$i}{$suffix}"] = $like;
            }
        }

        return [$where, $params];
    }

    /** @return array<string, mixed>|null a visible product */
    public function findVisibleById(int $id): ?array
    {
        return $this->one("
            SELECT p.*, c.name AS category_name, c.slug AS category_slug
            FROM products p
            JOIN categories c ON c.id = p.category_id
            WHERE p.id = :id AND p.is_active = 1 AND p.import_status = 'approved'
        ", ['id' => $id]);
    }

    /** @return array<string, mixed>|null any product, regardless of status */
    public function findForAdmin(int $id): ?array
    {
        return $this->one('
            SELECT p.*, c.name AS category_name
            FROM products p
            JOIN categories c ON c.id = p.category_id
            WHERE p.id = :id
        ', ['id' => $id]);
    }

    /** @param int|null $lowStockAtOrBelow only products with an active variant at or below this stock */
    public function countForAdmin(?string $status, ?int $lowStockAtOrBelow = null): int
    {
        [$where, $params] = $this->adminFilter($status, $lowStockAtOrBelow);

        return (int) $this->value('SELECT COUNT(*) FROM products p' . $where, $params);
    }

    /** @return list<array<string, mixed>> one page of the admin product table */
    public function pageForAdmin(?string $status, ?string $sort, string $dir, int $limit, int $offset, ?int $lowStockAtOrBelow = null): array
    {
        [$where, $params] = $this->adminFilter($status, $lowStockAtOrBelow);
        $sql = '
            SELECT p.id, p.name, p.category_id, p.short_description, p.long_description,
                   p.image_path, p.images, c.name AS category_name,
                   p.import_status, COUNT(v.id) AS variant_count
            FROM products p
            JOIN categories c ON c.id = p.category_id
            LEFT JOIN product_variants v ON v.product_id = p.id
        ' . $where;
        $sql .= ' GROUP BY p.id';
        $sql .= $sort !== null && isset(self::ADMIN_SORT_COLUMNS[$sort])
            ? ' ORDER BY ' . self::ADMIN_SORT_COLUMNS[$sort] . ($dir === 'desc' ? ' DESC' : ' ASC')
            : ' ORDER BY p.created_at DESC';
        $sql .= sprintf(' LIMIT %d OFFSET %d', $limit, $offset);

        return $this->all($sql, $params);
    }

    /**
     * WHERE clause shared by the admin count and page queries.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function adminFilter(?string $status, ?int $lowStockAtOrBelow): array
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

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    /** @return list<array<string, mixed>> id, name, image_path of every product (for image regeneration) */
    public function findAllForImages(): array
    {
        return $this->all('SELECT id, name, image_path FROM products ORDER BY id');
    }

    /** @param array{category_id: int, name: string, short_description: string, long_description: string} $data */
    public function create(array $data): int
    {
        $this->run("
            INSERT INTO products (category_id, name, short_description, long_description, import_status)
            VALUES (:category_id, :name, :short_description, :long_description, 'created')
        ", $data);

        return $this->lastId();
    }

    /** @param array<string, mixed> $fields name, category_id, short_description, long_description */
    public function update(int $id, array $fields): void
    {
        $allowed = array_intersect_key($fields, array_flip(['name', 'category_id', 'short_description', 'long_description']));
        if ($allowed === []) {
            return;
        }
        $set = implode(', ', array_map(static fn (string $c) => "{$c} = :{$c}", array_keys($allowed)));
        $this->run("UPDATE products SET {$set} WHERE id = :id", $allowed + ['id' => $id]);
    }

    /** @param list<int> $ids */
    public function setStatus(array $ids, string $status): int
    {
        if ($ids === [] || !in_array($status, self::STATUSES, true)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return $this->run("UPDATE products SET import_status = ? WHERE id IN ({$placeholders})", [$status, ...$ids])->rowCount();
    }

    /** @return list<string> stored image paths, main image first */
    public function imagePaths(int $id): array
    {
        $decoded = json_decode((string) $this->value('SELECT images FROM products WHERE id = :id', ['id' => $id]), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /** @param array<int, string> $paths main image first (re-indexed before saving) */
    public function saveImagePaths(int $id, array $paths): void
    {
        $this->run('UPDATE products SET images = :images, image_path = :main WHERE id = :id', [
            'images' => $paths !== [] ? json_encode(array_values($paths)) : null,
            'main'   => $paths[0] ?? null,
            'id'     => $id,
        ]);
    }

    /** @throws \PDOException when orders still reference the product's variants */
    public function delete(int $id): void
    {
        $this->run('DELETE FROM products WHERE id = :id', ['id' => $id]);
    }
}
