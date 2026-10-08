<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ProductJsonRepository;
use App\Repository\ProductTranslationRepository;
use App\Repository\VariantRepository;
use App\Support\Paths;

/**
 * The whole catalogue (or a filtered part of it) as one JSON document: every
 * product with its category, variants, translations and images. Written to a
 * stream product by product, so a big shop never sits in memory as one string.
 *
 * Each image is listed with its path in this shop and an absolute URL; the
 * importer keeps images it already has and downloads the others from that
 * URL. With $embedImages the image bytes are included too (base64), so the
 * file also imports where the URL can't be reached (a local or private shop).
 *
 * Format (version 1):
 *   {"format": "storegeneric-products", "version": 1, "exported_at": …, "source": …,
 *    "currency": …, "language": …, "products": [
 *      {"id", "name", "short_description", "long_description" (HTML), "status", "is_active",
 *       "created_at", "category": {"name", "slug", "description"},
 *       "images": [{"path", "url", "data"?}], "variants": [{"sku", "label", "unit", "price", "stock", "is_active"}],
 *       "translations": {"pt": {"name", "short_description", "long_description"}}}]}
 */
final class ProductJsonExporter
{
    public const FORMAT = 'storegeneric-products';
    public const VERSION = 1;

    /** Products fetched with their variants and translations at a time. */
    private const CHUNK = 100;
    /** Images above this size aren't embedded (they stay listed with their URL). */
    private const MAX_EMBED_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly ProductJsonRepository $products,
        private readonly VariantRepository $variants,
        private readonly ProductTranslationRepository $translations,
        private readonly ProductTranslations $translationService,
        private readonly StoreSettings $store,
        private readonly SiteUrl $site,
        private readonly Paths $paths,
    ) {
    }

    /**
     * Writes the JSON document to $handle.
     *
     * @param resource $handle
     * @param int|null $lowStockAtOrBelow only products with an active variant at or below this stock
     * @return int number of products written
     */
    public function write($handle, ?string $status = null, ?int $lowStockAtOrBelow = null, bool $embedImages = false): int
    {
        $header = [
            'format'      => self::FORMAT,
            'version'     => self::VERSION,
            'exported_at' => date('c'),
            'source'      => $this->site->base(),
            'currency'    => $this->store->currency(),
            'language'    => $this->translationService->baseLocale(),
        ];
        fwrite($handle, rtrim((string) json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), '}') . ',"products":[');

        $count = 0;
        $chunk = [];
        foreach ($this->products->exportProducts($status, $lowStockAtOrBelow) as $row) {
            $chunk[] = $row;
            if (count($chunk) >= self::CHUNK) {
                $count += $this->writeChunk($handle, $chunk, $count > 0, $embedImages);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $count += $this->writeChunk($handle, $chunk, $count > 0, $embedImages);
        }
        fwrite($handle, "]}\n");

        return $count;
    }

    /**
     * @param resource                         $handle
     * @param list<array<string, mixed>>       $rows
     */
    private function writeChunk($handle, array $rows, bool $needsComma, bool $embedImages): int
    {
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $variants = $this->variants->findForProducts($ids);
        $translations = $this->translations->forProducts($ids);

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $product = $this->productData($row, $variants[$id] ?? [], $translations[$id] ?? [], $embedImages);
            fwrite($handle, ($needsComma ? ',' : '') . json_encode($product, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            $needsComma = true;
        }

        return count($rows);
    }

    /**
     * @param array<string, mixed>            $row
     * @param list<array<string, mixed>>      $variants
     * @param array<string, array<string, ?string>> $translations
     * @return array<string, mixed>
     */
    private function productData(array $row, array $variants, array $translations, bool $embedImages): array
    {
        $translationData = [];
        foreach ($translations as $locale => $fields) {
            $translationData[$locale] = array_filter($fields, static fn (?string $value): bool => $value !== null && $value !== '');
        }

        return [
            'id'                => (int) $row['id'],
            'name'              => (string) $row['name'],
            'short_description' => (string) $row['short_description'],
            'long_description'  => (string) $row['long_description'],
            'status'            => (string) $row['import_status'],
            'is_active'         => (bool) $row['is_active'],
            'created_at'        => (string) $row['created_at'],
            'category'          => [
                'name'        => (string) $row['category_name'],
                'slug'        => (string) $row['category_slug'],
                'description' => $row['category_description'] !== null ? (string) $row['category_description'] : null,
            ],
            'images'            => $this->images($row, $embedImages),
            'variants'          => array_map(static fn (array $v): array => [
                'sku'       => (string) $v['sku'],
                'label'     => $v['label'] !== null ? (string) $v['label'] : null,
                'unit'      => $v['unit'] !== null ? (string) $v['unit'] : null,
                'price'     => (string) $v['price'],
                'stock'     => (int) $v['stock'],
                'is_active' => (bool) $v['is_active'],
            ], $variants),
            'translations'      => (object) $translationData,
        ];
    }

    /**
     * Main image first. Placeholders and missing files are still listed (with
     * their URL) but never embedded.
     *
     * @param array<string, mixed> $row
     * @return list<array<string, string>>
     */
    private function images(array $row, bool $embed): array
    {
        $decoded = json_decode((string) ($row['images'] ?? ''), true);
        $paths = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        if ($paths === [] && !empty($row['image_path'])) {
            $paths = [(string) $row['image_path']];
        }

        $images = [];
        foreach ($paths as $path) {
            $image = ['path' => $path, 'url' => $this->site->absolute('/' . ltrim($path, '/'))];
            $file = $this->localFile($path);
            if ($embed && $file !== null && filesize($file) <= self::MAX_EMBED_BYTES) {
                $bytes = file_get_contents($file);
                if ($bytes !== false) {
                    $image['data'] = base64_encode($bytes);
                }
            }
            $images[] = $image;
        }

        return $images;
    }

    /** The file of a stored image under public/assets/images/, or null (other path, missing file). */
    private function localFile(string $path): ?string
    {
        if (!str_starts_with($path, 'assets/images/') || str_contains($path, '..')) {
            return null;
        }
        $file = $this->paths->public($path);

        return is_file($file) ? $file : null;
    }
}
