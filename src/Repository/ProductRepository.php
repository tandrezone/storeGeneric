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

    /** @return list<array<string, mixed>> visible products with their lowest variant price */
    public function findVisible(?string $categorySlug = null): array
    {
        $sql = "
            SELECT p.id, p.name, p.short_description, p.image_path,
                   c.name AS category_name, c.slug AS category_slug,
                   MIN(v.price) AS from_price
            FROM products p
            JOIN categories c ON c.id = p.category_id
            LEFT JOIN product_variants v ON v.product_id = p.id AND v.is_active = 1
            WHERE p.is_active = 1 AND p.import_status = 'approved'
        ";
        $params = [];
        if ($categorySlug !== null && $categorySlug !== '') {
            $sql .= ' AND c.slug = :slug';
            $params['slug'] = $categorySlug;
        }

        return $this->all($sql . ' GROUP BY p.id ORDER BY p.name ASC', $params);
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

    public function countForAdmin(?string $status): int
    {
        return $status === null
            ? (int) $this->value('SELECT COUNT(*) FROM products')
            : (int) $this->value('SELECT COUNT(*) FROM products WHERE import_status = :s', ['s' => $status]);
    }

    /** @return list<array<string, mixed>> one page of the admin product table */
    public function pageForAdmin(?string $status, ?string $sort, string $dir, int $limit, int $offset): array
    {
        $sql = '
            SELECT p.id, p.name, p.category_id, p.short_description, p.long_description,
                   p.image_path, p.images, c.name AS category_name,
                   p.import_status, COUNT(v.id) AS variant_count
            FROM products p
            JOIN categories c ON c.id = p.category_id
            LEFT JOIN product_variants v ON v.product_id = p.id
        ';
        $params = [];
        if ($status !== null) {
            $sql .= ' WHERE p.import_status = :status';
            $params['status'] = $status;
        }
        $sql .= ' GROUP BY p.id';
        $sql .= $sort !== null && isset(self::ADMIN_SORT_COLUMNS[$sort])
            ? ' ORDER BY ' . self::ADMIN_SORT_COLUMNS[$sort] . ($dir === 'desc' ? ' DESC' : ' ASC')
            : ' ORDER BY p.created_at DESC';
        $sql .= sprintf(' LIMIT %d OFFSET %d', $limit, $offset);

        return $this->all($sql, $params);
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
