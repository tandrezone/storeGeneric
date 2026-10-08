<?php

declare(strict_types=1);

namespace App\Service;

use App\I18n\Translator;
use App\Infrastructure\Database;
use App\Repository\CategoryRepository;
use App\Repository\ProductCsvRepository;
use App\Repository\ProductJsonRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductTranslationRepository;
use App\Repository\VariantRepository;
use App\Security\HtmlSanitizer;
use App\Support\Paths;
use PDOException;
use RuntimeException;

/**
 * Imports a document written by ProductJsonExporter: products with their
 * category, variants, translations and images.
 *
 * Products are matched by variant SKU (a product without variants by its name
 * + category): a match is updated — fields present in the file overwrite,
 * variants are matched by SKU and added when new, translations are replaced
 * per language — and anything else becomes a new product. A field missing
 * from the file is left alone.
 *
 * Images: one that is already stored locally for the matched product is kept;
 * one with embedded data is decoded and stored; any other is downloaded from
 * its URL (through ImageDownloader, which refuses private addresses) and
 * stored under assets/images/products/. Images that can't be fetched are
 * reported and skipped — they never fail the product. Existing images that
 * the file doesn't list stay, after the listed ones.
 *
 * run(..., apply: false) is the dry run behind the preview. With apply: true
 * every product is written in its own transaction (a failing product doesn't
 * undo the others); its images are fetched after that.
 */
final class ProductJsonImporter
{
    public const MAX_BYTES = 64 * 1024 * 1024;
    public const MAX_PRODUCTS = 5000;
    public const MAX_VARIANTS = 200;
    public const MAX_IMAGES = 20;

    /** Column sizes from database/schema.sql. */
    private const MAX_NAME = 180;
    private const MAX_SHORT_DESCRIPTION = 280;
    private const MAX_LONG_DESCRIPTION_BYTES = 65535;
    private const MAX_CATEGORY = 100;
    private const MAX_VARIANT_FIELD = 64;
    private const MAX_STOCK = 4294967295;
    private const MAX_PRICE = 99999999.99;

    private const LOCAL_IMAGE_PATH = '#^assets/images/products/[A-Za-z0-9._-]+$#';
    private const TRUE_VALUES = ['1', 'yes', 'y', 'true', 'active', 'on'];
    private const FALSE_VALUES = ['0', 'no', 'n', 'false', 'inactive', 'off'];

    public function __construct(
        private readonly Database $db,
        private readonly ProductJsonRepository $lookup,
        private readonly ProductCsvRepository $csvLookup,
        private readonly ProductRepository $products,
        private readonly VariantRepository $variants,
        private readonly CategoryRepository $categories,
        private readonly ProductTranslationRepository $translationRows,
        private readonly ProductTranslations $translations,
        private readonly HtmlSanitizer $sanitizer,
        private readonly ImageDownloader $downloader,
        private readonly Paths $paths,
        private readonly Translator $translator,
    ) {
    }

    /** @param array<string, mixed> $params */
    private function t(string $message, array $params = []): string
    {
        return $this->translator->trans($message, $params);
    }

    /**
     * Decodes and checks the document.
     *
     * @return list<mixed> the products
     * @throws RuntimeException with a readable message
     */
    public function parse(string $json): array
    {
        $json = preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? $json;
        try {
            $document = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException($this->t('The file is not valid JSON ({error}).', ['error' => $e->getMessage()]));
        }
        if (!is_array($document)) {
            throw new RuntimeException($this->t('The file is not a product export.'));
        }

        if (array_is_list($document)) {
            $products = $document; // a bare list of products
        } else {
            if (($document['format'] ?? ProductJsonExporter::FORMAT) !== ProductJsonExporter::FORMAT) {
                throw new RuntimeException($this->t('The file is not a product export.'));
            }
            if ((int) ($document['version'] ?? 1) > ProductJsonExporter::VERSION) {
                throw new RuntimeException($this->t('The file was written by a newer version of this software (format {version}).', ['version' => (int) $document['version']]));
            }
            $products = $document['products'] ?? null;
            if (!is_array($products) || !array_is_list($products)) {
                throw new RuntimeException($this->t('The file has no "products" list.'));
            }
        }

        if ($products === []) {
            throw new RuntimeException($this->t('The file has no products.'));
        }
        if (count($products) > self::MAX_PRODUCTS) {
            throw new RuntimeException($this->t('The file has more than {max} products — split it up.', ['max' => self::MAX_PRODUCTS]));
        }

        return $products;
    }

    /**
     * @param list<mixed>                                                                  $products from parse()
     * @param array{create_categories?: bool, update_existing?: bool, images?: bool}        $options
     * @param ?callable(int, int, array<string, mixed>): void                               $progress called after each product (number, total, report row)
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>, applied: bool}
     */
    public function run(array $products, array $options, bool $apply, ?callable $progress = null): array
    {
        $options += ['create_categories' => false, 'update_existing' => true, 'images' => true];

        $normalized = [];
        foreach ($products as $raw) {
            $normalized[] = $this->normalize($raw);
        }

        $skus = [];
        foreach ($normalized as [$data]) {
            foreach ($data['variants'] ?? [] as $variant) {
                $skus[] = $variant['sku'];
            }
        }
        $existingBySku = $skus === [] ? [] : $this->csvLookup->variantsBySku($skus);
        $categoryIds = $this->csvLookup->categoryIdsByName();

        /** @var array<string, int> $counts */
        $counts = array_fill_keys([
            'create', 'update', 'unchanged', 'skip', 'error', 'variants_added', 'variants_updated', 'translations',
            'images_local', 'images_download', 'images_embedded', 'images_failed', 'images_skipped', 'new_categories',
        ], 0);
        $rows = [];
        $seenSkus = [];
        $total = count($normalized);

        foreach ($normalized as $i => [$data, $errors, $warnings]) {
            $entry = [
                'number'   => $i + 1,
                'name'     => (string) ($data['name'] ?? ''),
                'sku'      => (string) ($data['variants'][0]['sku'] ?? ''),
                'action'   => 'error',
                'changes'  => [],
                'errors'   => $errors,
                'warnings' => $warnings,
                'images'   => ['local' => 0, 'download' => 0, 'embedded' => 0, 'failed' => 0, 'skipped' => 0],
            ];

            foreach ($data['variants'] ?? [] as $variant) {
                $key = mb_strtolower($variant['sku']);
                if (isset($seenSkus[$key])) {
                    $entry['errors'][] = $this->t('SKU "{sku}" already appears in product {number}.', ['sku' => $variant['sku'], 'number' => $seenSkus[$key]]);
                } else {
                    $seenSkus[$key] = $i + 1;
                }
            }

            if ($entry['errors'] === []) {
                $entry = $this->process($entry, $data, $options, $apply, $existingBySku, $categoryIds, $counts);
            }
            if ($entry['errors'] !== []) {
                $entry['action'] = 'error';
            }
            if ($entry['action'] === 'error') {
                $counts['error']++;
            }

            $rows[] = $entry;
            if ($progress !== null) {
                $progress($i + 1, $total, $entry);
            }
        }

        return ['rows' => $rows, 'counts' => $counts, 'applied' => $apply];
    }

    /**
     * Plans (and with $apply writes) one product.
     *
     * @param array<string, mixed>                 $entry
     * @param array<string, mixed>                 $data
     * @param array<string, mixed>                 $options
     * @param array<string, array<string, mixed>>  $existingBySku lower-cased SKU → variant row with product_id
     * @param array<string, int>                   $categoryIds lower-cased name → id (new categories are added)
     * @param array<string, int>                   $counts
     * @return array<string, mixed> the entry
     */
    private function process(array $entry, array $data, array $options, bool $apply, array $existingBySku, array &$categoryIds, array &$counts): array
    {
        // --- which product is this?
        $matchedIds = [];
        foreach ($data['variants'] as $variant) {
            $found = $existingBySku[mb_strtolower($variant['sku'])] ?? null;
            if ($found !== null) {
                $matchedIds[(int) $found['product_id']] = true;
            }
        }
        if (count($matchedIds) > 1) {
            $entry['errors'][] = $this->t('The SKUs of this product belong to different products here ({ids}).', ['ids' => implode(', ', array_keys($matchedIds))]);

            return $entry;
        }

        $categoryName = $data['category']['name'] ?? null;
        $categoryKey = $categoryName !== null ? mb_strtolower($categoryName) : null;
        $categoryId = $categoryKey !== null ? ($categoryIds[$categoryKey] ?? null) : null;

        $productId = $matchedIds === [] ? null : (int) array_key_first($matchedIds);
        if ($productId === null && $data['variants'] === [] && $data['name'] !== null && $categoryId !== null) {
            $productId = (int) ($this->lookup->findByNameAndCategory($data['name'], $categoryId)['id'] ?? 0) ?: null;
        }
        $existing = $productId !== null ? $this->products->findForAdmin($productId) : null;
        if ($productId !== null && $existing === null) {
            $entry['errors'][] = $this->t('The matching product no longer exists.');

            return $entry;
        }

        if ($existing !== null && !$options['update_existing']) {
            $entry['action'] = 'skip';
            $entry['changes'][] = $this->t('Already exists (product {id}) — left as it is.', ['id' => $productId]);
            $counts['skip']++;

            return $entry;
        }

        // --- what has to be written?
        $newCategory = false;
        $fields = [];
        if ($existing === null) {
            foreach (['name' => 'name', 'short_description' => 'short description'] as $key => $label) {
                if (($data[$key] ?? null) === null) {
                    $entry['errors'][] = $this->t('A new product needs a {field}.', ['field' => $this->t($label)]);
                }
            }
            if ($categoryName === null) {
                $entry['errors'][] = $this->t('A new product needs a category.');
            }
        }
        if ($categoryName !== null && $categoryId === null) {
            if (!$options['create_categories']) {
                $entry['errors'][] = $this->t('Category "{category}" does not exist (tick "Create missing categories" to add it).', ['category' => $categoryName]);
            } else {
                $newCategory = true;
            }
        }
        if ($entry['errors'] !== []) {
            return $entry;
        }

        $labels = ['name' => 'Name', 'short_description' => 'Short description', 'long_description' => 'Long description', 'import_status' => 'Status', 'is_active' => 'Active'];
        $wanted = [
            'name'              => $data['name'],
            'short_description' => $data['short_description'],
            'long_description'  => $data['long_description'],
            'import_status'     => $data['status'],
            'is_active'         => $data['is_active'] === null ? null : (int) $data['is_active'],
        ];
        foreach ($wanted as $key => $value) {
            if ($value === null) {
                continue;
            }
            if ($existing === null || (string) $existing[$key] !== (string) $value) {
                $fields[$key] = $value;
                if ($existing !== null) {
                    $entry['changes'][] = $this->t($labels[$key]);
                }
            }
        }
        if ($categoryId !== null && $existing !== null && (int) $existing['category_id'] !== $categoryId) {
            $fields['category_id'] = $categoryId;
            $entry['changes'][] = $this->t('Category');
        }
        if ($newCategory) {
            $entry['changes'][] = $this->t('New category "{category}"', ['category' => $categoryName]);
        }

        $existingVariants = [];
        foreach ($productId !== null ? ($this->variants->findForProducts([$productId])[$productId] ?? []) : [] as $row) {
            $existingVariants[mb_strtolower((string) $row['sku'])] = $row;
        }
        $variantWrites = [];
        foreach ($data['variants'] as $variant) {
            $current = $existingVariants[mb_strtolower($variant['sku'])] ?? null;
            if ($current === null) {
                $variantWrites[] = ['create', $variant, null];
                $entry['changes'][] = $this->t('Add variant {sku}', ['sku' => $variant['sku']]);
            } elseif ($this->variantDiffers($current, $variant)) {
                $variantWrites[] = ['update', $variant, (int) $current['id']];
                $entry['changes'][] = $this->t('Update variant {sku}', ['sku' => $variant['sku']]);
            }
        }

        $currentTranslations = $productId !== null ? $this->translationRows->forProduct($productId) : [];
        $translationWrites = [];
        foreach ($data['translations'] as $locale => $values) {
            if ($this->translationDiffers($currentTranslations[$locale] ?? [], $values)) {
                $translationWrites[$locale] = $values;
                $entry['changes'][] = $this->t('Translation: {language}', ['language' => $this->translations->locales()[$locale] ?? $locale]);
            }
        }

        $existingImages = $productId !== null ? $this->products->imagePaths($productId) : [];
        $plan = $this->planImages($data['images'], $existingImages, (bool) $options['images'], $productId);
        foreach (['local', 'download', 'embedded', 'skipped'] as $kind) {
            $entry['images'][$kind] = count(array_filter($plan, static fn (array $p): bool => $p['kind'] === $kind));
        }
        $entry['images']['missing'] = count(array_filter($plan, static fn (array $p): bool => $p['kind'] === 'missing'));
        $entry['warnings'] = array_merge($entry['warnings'], array_map(
            fn (int $n): string => $this->t('Image {number} has no usable URL or data — skipped.', ['number' => $n]),
            array_keys(array_filter($plan, static fn (array $p): bool => $p['kind'] === 'missing'))
        ));
        $imageWork = $entry['images']['download'] + $entry['images']['embedded'];
        if ($imageWork > 0) {
            $entry['changes'][] = $this->t('{count} image(s) to store', ['count' => $imageWork]);
        }

        $isCreate = $existing === null;
        $hasChanges = $isCreate || $fields !== [] || $variantWrites !== [] || $translationWrites !== [] || $newCategory || $imageWork > 0;
        $entry['action'] = $isCreate ? 'create' : ($hasChanges ? 'update' : 'unchanged');

        if (!$apply) {
            $this->count($counts, $entry['action'], $variantWrites, $translationWrites, $entry['images'], $newCategory);
            if ($newCategory) {
                $categoryIds[(string) $categoryKey] = 0; // later products of the file see it as existing (and don't count it again)
            }

            return $entry;
        }
        if (!$hasChanges) {
            $counts['unchanged']++;
            $counts['images_local'] += (int) $entry['images']['local'];

            return $entry;
        }

        // --- write it: the product, variants and translations in one transaction ...
        $createdCategoryId = null;
        try {
            $productId = $this->db->transaction(function () use ($isCreate, $productId, $data, $fields, $newCategory, $categoryId, $categoryName, $variantWrites, $translationWrites, &$createdCategoryId): int {
                if ($newCategory) {
                    $createdCategoryId = $this->categories->create((string) $categoryName, $data['category']['description'] ?? null);
                    $categoryId = $createdCategoryId;
                }
                if (!$isCreate && $newCategory) {
                    $fields['category_id'] = $categoryId;
                }

                if ($isCreate) {
                    $created = [
                        'category_id'       => (int) $categoryId,
                        'name'              => (string) ($fields['name'] ?? ''),
                        'short_description' => (string) ($fields['short_description'] ?? ''),
                        'long_description'  => (string) ($fields['long_description'] ?? ''),
                    ];
                    if (isset($fields['import_status'])) {
                        $created['import_status'] = (string) $fields['import_status'];
                    }
                    if (isset($fields['is_active'])) {
                        $created['is_active'] = (int) $fields['is_active'];
                    }
                    $productId = $this->products->create($created);
                } else {
                    $productId = (int) $productId;
                    if ($fields !== []) {
                        $this->products->update($productId, $fields);
                    }
                }

                foreach ($variantWrites as [$kind, $variant, $variantId]) {
                    $row = [
                        'sku'       => $variant['sku'],
                        'label'     => $variant['label'],
                        'unit'      => $variant['unit'],
                        'price'     => $variant['price'],
                        'stock'     => $variant['stock'],
                        'is_active' => (int) $variant['is_active'],
                    ];
                    if ($kind === 'create') {
                        $this->variants->create(['product_id' => $productId] + $row);
                    } else {
                        $this->variants->update((int) $variantId, $productId, $row);
                    }
                }

                foreach ($translationWrites as $locale => $values) {
                    $errors = $this->translations->save($productId, $locale, $values);
                    if ($errors !== []) {
                        throw new RuntimeException(implode(' ', $errors));
                    }
                }

                return $productId;
            });
        } catch (PDOException $e) {
            $entry['errors'][] = $this->t('Database error: {message}', ['message' => $e->getMessage()]);

            return $entry;
        } catch (RuntimeException $e) {
            $entry['errors'][] = $e->getMessage();

            return $entry;
        }
        if ($createdCategoryId !== null && $categoryKey !== null) {
            $categoryIds[$categoryKey] = $createdCategoryId;
        }

        // ... then its images (slow, and a failed download must not undo the product).
        $this->storeImages($productId, $plan, $existingImages, $entry);

        $this->count($counts, $entry['action'], $variantWrites, $translationWrites, $entry['images'], $newCategory);
        $counts['images_failed'] += (int) $entry['images']['failed'];

        return $entry;
    }

    /**
     * Decides, per image of the file, what to do with it.
     *
     * @param list<array{path: ?string, url: ?string, data: ?string}> $images
     * @param list<string>                                             $existingPaths the product's stored images
     * @param int|null                                                 $productId     null for a product that doesn't exist yet
     * @return array<int, array{kind: string, path: ?string, url: ?string, data: ?string}> by 1-based image number
     */
    private function planImages(array $images, array $existingPaths, bool $fetch, ?int $productId): array
    {
        $plan = [];
        foreach ($images as $i => $image) {
            $local = $image['path'] !== null && in_array($image['path'], $existingPaths, true) && is_file($this->paths->public($image['path']));
            if (!$local) {
                // Same file as one the product has (an earlier import of this image, or the same bytes uploaded by hand)?
                $same = $this->sameStoredImage($image, $existingPaths, $productId);
                if ($same !== null) {
                    $local = true;
                    $image['path'] = $same;
                }
            }
            if ($local) {
                $kind = 'local';
            } elseif (!$fetch) {
                $kind = 'skipped';
            } elseif ($image['data'] !== null) {
                $kind = 'embedded';
            } elseif ($image['url'] !== null) {
                $kind = 'download';
            } else {
                $kind = 'missing';
            }
            $plan[$i + 1] = ['kind' => $kind] + $image;
        }

        return $plan;
    }

    /**
     * The product's stored image that this entry would produce again: one
     * with the same bytes (embedded data), or the file ImageDownloader names
     * after this URL. Null when there is none.
     *
     * @param array{path: ?string, url: ?string, data: ?string} $image
     * @param list<string>                                      $existingPaths
     */
    private function sameStoredImage(array $image, array $existingPaths, ?int $productId): ?string
    {
        if ($productId === null || $existingPaths === []) {
            return null;
        }

        $hash = null;
        $prefix = null;
        if ($image['data'] !== null) {
            $bytes = base64_decode($image['data'], true);
            $hash = $bytes !== false ? md5($bytes) : null;
        } elseif ($image['url'] !== null) {
            $prefix = $productId . '-' . substr(md5($image['url']), 0, 12) . '.';
        }

        foreach ($existingPaths as $path) {
            $file = $this->paths->public($path);
            if (!str_starts_with($path, 'assets/images/') || str_contains($path, '..') || !is_file($file)) {
                continue;
            }
            if ($hash !== null && md5_file($file) === $hash) {
                return $path;
            }
            if ($prefix !== null && str_starts_with(basename($path), $prefix)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Stores the images that aren't local yet and saves the product's image
     * list: the file's images in its order, then any it already had that the
     * file doesn't list. Failures are added to the entry's warnings.
     *
     * @param array<int, array{kind: string, path: ?string, url: ?string, data: ?string}> $plan
     * @param list<string>                                                                 $existingPaths
     * @param array<string, mixed>                                                         $entry
     */
    private function storeImages(int $productId, array $plan, array $existingPaths, array &$entry): void
    {
        $resolved = [];
        $stored = false;
        foreach ($plan as $number => $image) {
            $path = null;
            if ($image['kind'] === 'local') {
                $path = $image['path'];
            } elseif ($image['kind'] === 'embedded') {
                $bytes = base64_decode((string) $image['data'], true);
                $path = $bytes !== false ? $this->downloader->storeBytes($bytes, $productId . '-' . substr(md5($bytes), 0, 12)) : null;
                $stored = $stored || $path !== null;
            } elseif ($image['kind'] === 'download') {
                $path = $this->downloader->download((string) $image['url'], $productId);
                $stored = $stored || $path !== null;
            } else {
                continue;
            }

            if ($path === null) {
                $entry['images'][$image['kind']]--;
                $entry['images']['failed']++;
                $entry['warnings'][] = $this->t('Image {number} could not be stored ({source}).', [
                    'number' => $number,
                    'source' => $image['kind'] === 'embedded' ? $this->t('invalid image data') : (string) $image['url'],
                ]);
            } else {
                $resolved[] = $path;
            }
        }

        if ($resolved !== []) {
            $list = array_values(array_unique(array_merge($resolved, $existingPaths)));
            if ($stored || $list !== $existingPaths) {
                $this->products->saveImagePaths($productId, $list);
            }
        }
    }

    /**
     * @param array<string, int>                 $counts
     * @param list<array{0: string, 1: array<string, mixed>, 2: ?int}> $variantWrites
     * @param array<string, array<string, string>> $translationWrites
     * @param array<string, int>                 $images
     */
    private function count(array &$counts, string $action, array $variantWrites, array $translationWrites, array $images, bool $newCategory): void
    {
        $counts[$action]++;
        foreach ($variantWrites as [$kind]) {
            $counts[$kind === 'create' ? 'variants_added' : 'variants_updated']++;
        }
        $counts['translations'] += count($translationWrites);
        $counts['images_local'] += $images['local'];
        $counts['images_download'] += $images['download'];
        $counts['images_embedded'] += $images['embedded'];
        $counts['images_skipped'] += $images['skipped'];
        if ($newCategory) {
            $counts['new_categories']++;
        }
    }

    /**
     * @param array<string, mixed> $current a product_variants row
     * @param array<string, mixed> $variant normalized from the file
     */
    private function variantDiffers(array $current, array $variant): bool
    {
        return (string) ($current['label'] ?? '') !== (string) ($variant['label'] ?? '')
            || (string) ($current['unit'] ?? '') !== (string) ($variant['unit'] ?? '')
            || abs((float) $current['price'] - (float) $variant['price']) >= 0.005
            || (int) $current['stock'] !== (int) $variant['stock']
            || (bool) $current['is_active'] !== (bool) $variant['is_active'];
    }

    /**
     * @param array<string, ?string> $current
     * @param array<string, string>  $values
     */
    private function translationDiffers(array $current, array $values): bool
    {
        foreach (ProductTranslationRepository::FIELDS as $field) {
            if ((string) ($current[$field] ?? '') !== (string) ($values[$field] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cleans one product of the file into the shape run() works with.
     *
     * @return array{0: array<string, mixed>, 1: list<string>, 2: list<string>} data, errors, warnings
     */
    private function normalize(mixed $raw): array
    {
        $errors = [];
        $warnings = [];
        $data = [
            'name' => null, 'short_description' => null, 'long_description' => null, 'status' => null, 'is_active' => null,
            'category' => null, 'variants' => [], 'translations' => [], 'images' => [],
        ];
        if (!is_array($raw) || array_is_list($raw)) {
            return [$data, [$this->t('This entry is not a product.')], []];
        }

        $text = static fn (mixed $value): ?string => is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : null;

        $name = $text($raw['name'] ?? null);
        $data['name'] = $name;
        if ($name !== null && ($name === '' || mb_strlen($name) > self::MAX_NAME)) {
            $errors[] = $name === '' ? $this->t('The name is empty.') : $this->t('The name can be at most {max} characters.', ['max' => self::MAX_NAME]);
        }
        $short = $text($raw['short_description'] ?? null);
        $data['short_description'] = $short;
        if ($short !== null && ($short === '' || mb_strlen($short) > self::MAX_SHORT_DESCRIPTION)) {
            $errors[] = $short === '' ? $this->t('The short description is empty.') : $this->t('The short description can be at most {max} characters.', ['max' => self::MAX_SHORT_DESCRIPTION]);
        }
        if (isset($raw['long_description']) && is_string($raw['long_description'])) {
            $long = $this->sanitizer->clean($raw['long_description']);
            if (strlen($long) > self::MAX_LONG_DESCRIPTION_BYTES) {
                $errors[] = $this->t('The long description is too long.');
            }
            $data['long_description'] = $long;
        }

        $status = $text($raw['status'] ?? $raw['import_status'] ?? null);
        if ($status !== null && $status !== '') {
            if (in_array($status, ProductRepository::STATUSES, true)) {
                $data['status'] = $status;
            } else {
                $warnings[] = $this->t('Unknown status "{status}" — ignored.', ['status' => $status]);
            }
        }
        $data['is_active'] = $this->bool($raw['is_active'] ?? $raw['active'] ?? null);

        $category = $raw['category'] ?? null;
        $categoryName = is_array($category) ? $text($category['name'] ?? null) : $text($category);
        if ($categoryName !== null) {
            if ($categoryName === '' || mb_strlen($categoryName) > self::MAX_CATEGORY) {
                $errors[] = $this->t('The category name is empty or longer than {max} characters.', ['max' => self::MAX_CATEGORY]);
            } else {
                $description = is_array($category) ? $text($category['description'] ?? null) : null;
                $data['category'] = ['name' => $categoryName, 'description' => $description !== '' ? $description : null];
            }
        }

        $this->normalizeVariants($raw['variants'] ?? [], $data, $errors);
        $this->normalizeTranslations($raw['translations'] ?? [], $data, $errors, $warnings);
        $this->normalizeImages($raw['images'] ?? [], $data, $warnings);

        return [$data, $errors, $warnings];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $errors
     */
    private function normalizeVariants(mixed $variants, array &$data, array &$errors): void
    {
        if (!is_array($variants) || ($variants !== [] && !array_is_list($variants))) {
            $errors[] = $this->t('"variants" must be a list.');

            return;
        }
        if (count($variants) > self::MAX_VARIANTS) {
            $errors[] = $this->t('A product can have at most {max} variants.', ['max' => self::MAX_VARIANTS]);

            return;
        }

        $seen = [];
        foreach ($variants as $i => $raw) {
            $n = $i + 1;
            if (!is_array($raw)) {
                $errors[] = $this->t('Variant {number} is not an object.', ['number' => $n]);
                continue;
            }
            $sku = is_scalar($raw['sku'] ?? null) ? trim((string) $raw['sku']) : '';
            if ($sku === '' || mb_strlen($sku) > self::MAX_VARIANT_FIELD) {
                $errors[] = $this->t('Variant {number}: the SKU is missing or longer than {max} characters.', ['number' => $n, 'max' => self::MAX_VARIANT_FIELD]);
                continue;
            }
            if (isset($seen[mb_strtolower($sku)])) {
                $errors[] = $this->t('SKU "{sku}" appears twice in this product.', ['sku' => $sku]);
                continue;
            }
            $seen[mb_strtolower($sku)] = true;

            $price = $raw['price'] ?? null;
            if (!is_numeric($price) || (float) $price < 0 || (float) $price > self::MAX_PRICE) {
                $errors[] = $this->t('Variant {sku}: the price must be a number from 0 to {max}.', ['sku' => $sku, 'max' => number_format(self::MAX_PRICE, 2, '.', '')]);
                continue;
            }
            $stock = $raw['stock'] ?? 0;
            if (!is_numeric($stock) || (float) $stock < 0 || (float) $stock > self::MAX_STOCK || floor((float) $stock) !== (float) $stock) {
                $errors[] = $this->t('Variant {sku}: the stock must be a whole number of 0 or more.', ['sku' => $sku]);
                continue;
            }
            $label = is_scalar($raw['label'] ?? null) ? trim((string) $raw['label']) : '';
            $unit = is_scalar($raw['unit'] ?? null) ? trim((string) $raw['unit']) : '';
            if (mb_strlen($label) > self::MAX_VARIANT_FIELD || mb_strlen($unit) > self::MAX_VARIANT_FIELD) {
                $errors[] = $this->t('Variant {sku}: the label and unit can be at most {max} characters.', ['sku' => $sku, 'max' => self::MAX_VARIANT_FIELD]);
                continue;
            }

            $data['variants'][] = [
                'sku'       => $sku,
                'label'     => $label !== '' ? $label : null,
                'unit'      => $unit !== '' ? $unit : null,
                'price'     => round((float) $price, 2),
                'stock'     => (int) $stock,
                'is_active' => $this->bool($raw['is_active'] ?? $raw['active'] ?? null) ?? true,
            ];
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $errors
     * @param list<string>         $warnings
     */
    private function normalizeTranslations(mixed $translations, array &$data, array &$errors, array &$warnings): void
    {
        if (!is_array($translations)) {
            return;
        }
        foreach ($translations as $code => $values) {
            $locale = $this->translator->normalize((string) $code);
            if (!is_array($values) || $locale === null || !$this->translations->isTranslatable($locale)) {
                $warnings[] = $this->t('Language "{locale}" is not available here — its translation was skipped.', ['locale' => (string) $code]);
                continue;
            }

            $clean = [];
            foreach (ProductTranslationRepository::FIELDS as $field) {
                $value = $values[$field] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $clean[$field] = $field === 'long_description' ? $this->sanitizer->clean($value) : trim($value);
                }
            }
            if (
                isset($clean['name']) && mb_strlen($clean['name']) > ProductTranslations::MAX_NAME
                || isset($clean['short_description']) && mb_strlen($clean['short_description']) > ProductTranslations::MAX_SHORT
                || isset($clean['long_description']) && strlen($clean['long_description']) > ProductTranslations::MAX_LONG_BYTES
            ) {
                $errors[] = $this->t('The {language} translation has a field that is too long.', ['language' => $this->translations->locales()[$locale] ?? $locale]);
                continue;
            }
            if ($clean !== []) {
                $data['translations'][$locale] = $clean;
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $warnings
     */
    private function normalizeImages(mixed $images, array &$data, array &$warnings): void
    {
        if (!is_array($images)) {
            return;
        }
        if (count($images) > self::MAX_IMAGES) {
            $warnings[] = $this->t('Only the first {max} images are imported.', ['max' => self::MAX_IMAGES]);
            $images = array_slice($images, 0, self::MAX_IMAGES);
        }

        foreach (array_values($images) as $raw) {
            if (is_string($raw)) {
                $raw = ['url' => $raw];
            }
            if (!is_array($raw)) {
                $data['images'][] = ['path' => null, 'url' => null, 'data' => null];
                continue;
            }
            $path = is_string($raw['path'] ?? null) ? trim($raw['path']) : null;
            $url = is_string($raw['url'] ?? null) ? trim($raw['url']) : null;
            $bytes = is_string($raw['data'] ?? null) && $raw['data'] !== '' ? $raw['data'] : null;

            $data['images'][] = [
                'path' => $path !== null && preg_match(self::LOCAL_IMAGE_PATH, $path) === 1 && !str_contains($path, '..') ? $path : null,
                'url'  => $url !== null && preg_match('#^https?://#i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null,
                'data' => $bytes,
            ];
        }
    }

    private function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }
        if (is_string($value)) {
            $value = strtolower(trim($value));
            if (in_array($value, self::TRUE_VALUES, true)) {
                return true;
            }
            if (in_array($value, self::FALSE_VALUES, true)) {
                return false;
            }
        }

        return null;
    }
}
