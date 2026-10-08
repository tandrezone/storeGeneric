<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Per-language product text (name, short and long description). A missing
 * row or an empty field means "use the product's own text" — see
 * ProductRepository for how the storefront falls back.
 */
final class ProductTranslationRepository extends Repository
{
    public const FIELDS = ['name', 'short_description', 'long_description'];

    /** @return array<string, array<string, ?string>> locale => row (name, short_description, long_description) */
    public function forProduct(int $productId): array
    {
        return $this->forProducts([$productId])[$productId] ?? [];
    }

    /**
     * @param list<int> $productIds
     * @return array<int, array<string, array<string, ?string>>> product id => locale => row
     */
    public function forProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $grouped = [];
        foreach (
            $this->all("
            SELECT product_id, locale, name, short_description, long_description
            FROM product_translations
            WHERE product_id IN ({$placeholders})
            ORDER BY locale
        ", $productIds) as $row
        ) {
            $grouped[(int) $row['product_id']][(string) $row['locale']] = [
                'name'              => $row['name'],
                'short_description' => $row['short_description'],
                'long_description'  => $row['long_description'],
            ];
        }

        return $grouped;
    }

    /**
     * Saves one language's text. Empty fields are stored as NULL (fall back
     * to the original); when all three are empty the row is removed.
     *
     * @param array<string, string> $fields name, short_description, long_description
     */
    public function save(int $productId, string $locale, array $fields): void
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $text = trim((string) ($fields[$field] ?? ''));
            $values[$field] = $text === '' ? null : $text;
        }
        if ($values['name'] === null && $values['short_description'] === null && $values['long_description'] === null) {
            $this->delete($productId, $locale);

            return;
        }

        $this->run('
            INSERT INTO product_translations (product_id, locale, name, short_description, long_description)
            VALUES (:product_id, :locale, :name, :short_description, :long_description)
            ON DUPLICATE KEY UPDATE name = VALUES(name), short_description = VALUES(short_description), long_description = VALUES(long_description)
        ', ['product_id' => $productId, 'locale' => $locale] + $values);
    }

    public function delete(int $productId, string $locale): void
    {
        $this->run('DELETE FROM product_translations WHERE product_id = :product_id AND locale = :locale', [
            'product_id' => $productId,
            'locale'     => $locale,
        ]);
    }
}
