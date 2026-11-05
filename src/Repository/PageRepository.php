<?php

declare(strict_types=1);

namespace App\Repository;

/** Editable content pages (pages table). locale '' = every language. */
final class PageRepository extends Repository
{
    /** Slugs of the pages that exist as built-in templates; a row with one of these replaces the built-in text. */
    public const BUILT_IN = ['about', 'info', 'support', 'terms', 'privacy'];

    /** @return array<string, mixed>|null the page for a language, else the all-languages one (published only) */
    public function findPublished(string $slug, string $locale): ?array
    {
        return $this->one("
            SELECT * FROM pages WHERE slug = :s AND is_published = 1 AND locale IN (:l, '')
            ORDER BY locale = :l2 DESC LIMIT 1
        ", ['s' => $slug, 'l' => $locale, 'l2' => $locale]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM pages WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function findExact(string $slug, string $locale): ?array
    {
        return $this->one('SELECT * FROM pages WHERE slug = :s AND locale = :l', ['s' => $slug, 'l' => $locale]);
    }

    /** @return list<array<string, mixed>> */
    public function all_(): array
    {
        return $this->all('SELECT id, slug, locale, title, is_published, in_footer, sort_order, updated_at FROM pages ORDER BY slug, locale');
    }

    /**
     * Footer links: one per slug, in the visitor's language when there is such a page.
     *
     * @return list<array{slug: string, title: string}>
     */
    public function footerLinks(string $locale): array
    {
        $rows = $this->all("SELECT slug, locale, title, sort_order FROM pages WHERE is_published = 1 AND in_footer = 1 AND locale IN (:l, '') ORDER BY sort_order, title", ['l' => $locale]);
        $best = [];
        foreach ($rows as $row) {
            $slug = (string) $row['slug'];
            if (!isset($best[$slug]) || $row['locale'] === $locale) {
                $best[$slug] = $row;
            }
        }
        usort($best, static fn (array $a, array $b): int => [(int) $a['sort_order'], $a['title']] <=> [(int) $b['sort_order'], $b['title']]);

        return array_values(array_map(static fn (array $r): array => ['slug' => (string) $r['slug'], 'title' => (string) $r['title']], $best));
    }

    /** @return list<array<string, mixed>> published custom pages (sitemap) */
    public function publishedCustom(): array
    {
        $placeholders = implode(',', array_fill(0, count(self::BUILT_IN), '?'));

        return $this->all("SELECT DISTINCT slug, MAX(updated_at) AS updated_at FROM pages WHERE is_published = 1 AND slug NOT IN ({$placeholders}) GROUP BY slug", self::BUILT_IN);
    }

    /** @param array{slug: string, locale: string, title: string, body: string, is_published: int, in_footer: int, sort_order: int} $data */
    public function create(array $data): int
    {
        $this->run('
            INSERT INTO pages (slug, locale, title, body, is_published, in_footer, sort_order)
            VALUES (:slug, :locale, :title, :body, :is_published, :in_footer, :sort_order)
        ', $data);

        return $this->lastId();
    }

    /** @param array{title: string, body: string, is_published: int, in_footer: int, sort_order: int} $data */
    public function update(int $id, array $data): void
    {
        $this->run('UPDATE pages SET title = :title, body = :body, is_published = :is_published, in_footer = :in_footer, sort_order = :sort_order WHERE id = :id', $data + ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->run('DELETE FROM pages WHERE id = :id', ['id' => $id]);
    }
}
