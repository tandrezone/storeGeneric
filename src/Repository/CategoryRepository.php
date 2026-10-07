<?php

declare(strict_types=1);

namespace App\Repository;

final class CategoryRepository extends Repository
{
    /** @return list<array<string, mixed>> */
    public function findAll(): array
    {
        return $this->all('SELECT id, name, slug FROM categories ORDER BY name ASC');
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $this->one('SELECT id, name, slug FROM categories WHERE slug = :slug', ['slug' => $slug]);
    }

    /** @return list<array<string, mixed>> every category with how many products use it */
    public function findAllWithProductCounts(): array
    {
        return $this->all('
            SELECT c.id, c.name, c.slug, COUNT(p.id) AS product_count
            FROM categories c
            LEFT JOIN products p ON p.category_id = c.id
            GROUP BY c.id, c.name, c.slug
            ORDER BY c.name ASC
        ');
    }

    public function create(string $name): int
    {
        $this->run('INSERT INTO categories (name, slug) VALUES (:name, :slug)', ['name' => $name, 'slug' => $this->uniqueSlug($name)]);

        return $this->lastId();
    }

    /** Renames a category. The slug never changes, so storefront links stay valid. */
    public function rename(int $id, string $name): void
    {
        $this->run('UPDATE categories SET name = :name WHERE id = :id', ['name' => $name, 'id' => $id]);
    }

    /** @throws \PDOException when products still use the category */
    public function delete(int $id): void
    {
        $this->run('DELETE FROM categories WHERE id = :id', ['id' => $id]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'category';
        $slug = $base;
        for ($suffix = 2; $this->value('SELECT 1 FROM categories WHERE slug = :s', ['s' => $slug]); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
