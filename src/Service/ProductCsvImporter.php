<?php

declare(strict_types=1);

namespace App\Service;

use App\I18n\Translator;
use App\Infrastructure\Database;
use App\Repository\CategoryRepository;
use App\Repository\ProductCsvRepository;
use App\Repository\ProductRepository;
use App\Repository\VariantRepository;
use App\Support\Csv;
use RuntimeException;

/**
 * Admin → Products → Import CSV, in the export's format (one row per variant).
 *
 * Rows are matched by SKU: a known SKU updates that variant (price, stock,
 * label, unit, active) and its product (name, category, status, short
 * description); an unknown SKU becomes a new variant — of product_id when
 * given, otherwise of a new product (rows with the same name + category
 * share one new product). New products start as "created", like the add
 * form. Unknown categories are an error unless $createCategories is set.
 *
 * run(..., apply: false) is the dry run behind the preview; with apply: true
 * the same plan is written in one transaction. Rows with errors are skipped.
 */
final class ProductCsvImporter
{
    public const COLUMNS = ['product_id', 'name', 'category', 'status', 'short_description', 'sku', 'label', 'unit', 'price', 'stock', 'active'];

    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_ROWS = 5000;

    /** Column sizes from database/schema.sql. */
    private const MAX_NAME = 180;
    private const MAX_SHORT_DESCRIPTION = 280;
    private const MAX_CATEGORY = 100;
    private const MAX_VARIANT_FIELD = 64;
    private const MAX_STOCK = 4294967295;

    private const TRUE_VALUES = ['1', 'yes', 'y', 'true', 'active', 'on'];
    private const FALSE_VALUES = ['0', 'no', 'n', 'false', 'inactive', 'off'];

    public function __construct(
        private readonly Database $db,
        private readonly ProductCsvRepository $lookup,
        private readonly ProductRepository $products,
        private readonly VariantRepository $variants,
        private readonly CategoryRepository $categories,
        private readonly Translator $translator,
    ) {
    }

    /** @param array<string, mixed> $params */
    private function t(string $message, array $params = []): string
    {
        return $this->translator->trans($message, $params);
    }

    /**
     * Parses the file into rows keyed by column name (only the columns the
     * header has), each with its 1-based file line in `_line`.
     *
     * @return list<array<string, string|int>>
     * @throws RuntimeException with a message for the admin when the file can't be used
     */
    public function readRows(string $text): array
    {
        if (strlen($text) > self::MAX_BYTES) {
            throw new RuntimeException($this->t('The file is too large (max {mb} MB).', ['mb' => self::MAX_BYTES >> 20]));
        }
        $rows = Csv::parseLines($text, self::MAX_ROWS + 1);
        if ($rows === []) {
            throw new RuntimeException($this->t('The file is empty.'));
        }

        $headerLine = (int) array_key_first($rows);
        $header = array_map(
            static fn (string $h) => trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($h)), '_'),
            $rows[$headerLine]
        );
        unset($rows[$headerLine]); // (array_shift would renumber the line keys)
        if (!in_array('sku', $header, true)) {
            throw new RuntimeException($this->t('The first row must be a header with at least a "sku" column. Columns: {columns}.', ['columns' => implode(', ', self::COLUMNS)]));
        }
        $unknown = array_diff(array_filter($header), self::COLUMNS);
        if ($unknown !== []) {
            throw new RuntimeException($this->t('Unknown column(s): {columns}. Allowed: {allowed}.', ['columns' => implode(', ', $unknown), 'allowed' => implode(', ', self::COLUMNS)]));
        }
        if (count($header) !== count(array_unique($header))) {
            throw new RuntimeException($this->t('A column appears twice in the header.'));
        }
        if ($rows === []) {
            throw new RuntimeException($this->t('The file has a header but no rows.'));
        }

        $result = [];
        foreach ($rows as $line => $cells) {
            $row = ['_line' => $line];
            foreach ($header as $index => $column) {
                if ($column !== '') {
                    $row[$column] = (string) ($cells[$index] ?? '');
                }
            }
            $result[] = $row;
        }

        return $result;
    }

    /**
     * Checks and normalises one row's values. Absent columns (and empty
     * required values) are null = "leave unchanged".
     *
     * @param array<string, string|int> $row
     * @return array{0: array{product_id: ?int, name: ?string, category: ?string, status: ?string, short_description: ?string, sku: string, label: string|false|null, unit: string|false|null, price: ?float, stock: ?int, is_active: ?int}, 1: list<string>}
     *         label/unit: null = column absent, false = empty (clears it)
     */
    public function normalize(array $row): array
    {
        $errors = [];
        $text = static fn (string $key): ?string => array_key_exists($key, $row) && trim((string) $row[$key]) !== '' ? trim((string) $row[$key]) : null;

        $sku = $text('sku') ?? '';
        if ($sku === '' && $text('product_id') === null) {
            $errors[] = $this->t('SKU is required.');
        } elseif (mb_strlen($sku) > self::MAX_VARIANT_FIELD) {
            $errors[] = $this->t('SKU can be at most {max} characters.', ['max' => self::MAX_VARIANT_FIELD]);
        }

        $productId = null;
        if (($raw = $text('product_id')) !== null) {
            if (!ctype_digit($raw) || (int) $raw < 1) {
                $errors[] = $this->t('product_id "{value}" is not a valid id.', ['value' => $raw]);
            } else {
                $productId = (int) $raw;
            }
        }

        $name = $text('name');
        if ($name !== null && mb_strlen($name) > self::MAX_NAME) {
            $errors[] = $this->t('Name can be at most {max} characters.', ['max' => self::MAX_NAME]);
        }
        $category = $text('category');
        if ($category !== null && mb_strlen($category) > self::MAX_CATEGORY) {
            $errors[] = $this->t('Category can be at most {max} characters.', ['max' => self::MAX_CATEGORY]);
        }
        $short = $text('short_description');
        if ($short !== null && mb_strlen($short) > self::MAX_SHORT_DESCRIPTION) {
            $errors[] = $this->t('Short description can be at most {max} characters.', ['max' => self::MAX_SHORT_DESCRIPTION]);
        }

        $status = $text('status');
        if ($status !== null) {
            $status = strtolower($status);
            if (!in_array($status, ProductRepository::STATUSES, true)) {
                $errors[] = $this->t('Unknown status "{status}" (use {allowed}).', ['status' => $status, 'allowed' => implode(', ', ProductRepository::STATUSES)]);
            }
        }

        $optional = function (string $key) use ($row, &$errors): string|false|null {
            if (!array_key_exists($key, $row)) {
                return null;
            }
            $value = trim((string) $row[$key]);
            if (mb_strlen($value) > self::MAX_VARIANT_FIELD) {
                $errors[] = $key === 'label'
                    ? $this->t('Label can be at most {max} characters.', ['max' => self::MAX_VARIANT_FIELD])
                    : $this->t('Unit can be at most {max} characters.', ['max' => self::MAX_VARIANT_FIELD]);
            }

            return $value === '' ? false : $value;
        };
        $label = $optional('label');
        $unit = $optional('unit');

        $price = null;
        if (($raw = $text('price')) !== null) {
            $number = self::decimal($raw);
            if ($number === null || $number < 0) {
                $errors[] = $this->t('Price "{value}" is not a valid amount.', ['value' => $raw]);
            } elseif ($number > VariantRepository::MAX_PRICE) {
                $errors[] = $this->t('That price is too high.');
            } else {
                $price = round($number, 2);
            }
        }

        $stock = null;
        if (($raw = $text('stock')) !== null) {
            if (!ctype_digit($raw)) {
                $errors[] = $this->t('Stock "{value}" must be a whole number of 0 or more.', ['value' => $raw]);
            } elseif ((float) $raw > self::MAX_STOCK) {
                $errors[] = $this->t('That stock level is too high.');
            } else {
                $stock = (int) $raw;
            }
        }

        $active = null;
        if (($raw = $text('active')) !== null) {
            $lower = strtolower($raw);
            $active = match (true) {
                in_array($lower, self::TRUE_VALUES, true)  => 1,
                in_array($lower, self::FALSE_VALUES, true) => 0,
                default                                    => null,
            };
            if ($active === null) {
                $errors[] = $this->t('Active must be 1 or 0 (got "{value}").', ['value' => $raw]);
            }
        }

        return [[
            'product_id'        => $productId,
            'name'              => $name,
            'category'          => $category,
            'status'            => $status,
            'short_description' => $short,
            'sku'               => $sku,
            'label'             => $label,
            'unit'              => $unit,
            'price'             => $price,
            'stock'             => $stock,
            'is_active'         => $active,
        ], $errors];
    }

    /**
     * Plans (and with $apply, writes) the import.
     *
     * @param list<array<string, string|int>> $rows from readRows()
     * @return array{rows: list<array{line: int, sku: string, name: string, action: string, changes: list<string>, errors: list<string>}>, counts: array<string, int>, applied: bool}
     *         action: create_product | create_variant | update | unchanged | error
     */
    public function run(array $rows, bool $createCategories, bool $apply): array
    {
        $work = fn (): array => $this->process($rows, $createCategories, $apply);

        $report = $apply ? $this->db->transaction($work) : $work();
        $report['applied'] = $apply;

        return $report;
    }

    /**
     * @param list<array<string, string|int>> $rows
     * @return array{rows: list<array{line: int, sku: string, name: string, action: string, changes: list<string>, errors: list<string>}>, counts: array<string, int>}
     */
    private function process(array $rows, bool $createCategories, bool $apply): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = [(int) $row['_line'], ...$this->normalize($row)];
        }

        $skus = array_values(array_filter(array_map(static fn (array $n) => $n[1]['sku'], $normalized)));
        $existing = $skus === [] ? [] : $this->lookup->variantsBySku($skus);
        $productIds = array_values(array_unique(array_filter(array_map(static fn (array $n) => $n[1]['product_id'], $normalized))));
        $productsById = $productIds === [] ? [] : $this->lookup->productsById($productIds);
        $categoryIds = $this->lookup->categoryIdsByName();

        $seenSkus = [];
        $newProducts = [];   // "name|category" → product id (fake negative ids in a dry run)
        $newCategories = []; // lower-cased name → id
        $updatedProducts = []; // product id → fields already written by an earlier row
        $fakeId = 0;
        $report = [];
        $counts = ['create_product' => 0, 'create_variant' => 0, 'update' => 0, 'unchanged' => 0, 'error' => 0, 'new_categories' => 0];

        foreach ($normalized as [$line, $data, $errors]) {
            $entry = ['line' => $line, 'sku' => $data['sku'], 'name' => (string) $data['name'], 'action' => 'error', 'changes' => [], 'errors' => $errors];

            if ($data['sku'] !== '') {
                $key = mb_strtolower($data['sku']);
                if (isset($seenSkus[$key])) {
                    $entry['errors'][] = $this->t('SKU "{sku}" already appears on line {line}.', ['sku' => $data['sku'], 'line' => $seenSkus[$key]]);
                } else {
                    $seenSkus[$key] = $line;
                }
            }

            // Category name → id (maybe a new category).
            $categoryId = null;
            $createdCategory = null; // lower-cased name of a category this row creates
            if ($data['category'] !== null) {
                $catKey = mb_strtolower($data['category']);
                $categoryId = $categoryIds[$catKey] ?? $newCategories[$catKey] ?? null;
                if ($categoryId === null && $entry['errors'] === []) {
                    if (!$createCategories) {
                        $entry['errors'][] = $this->t('Category "{category}" does not exist (tick "Create missing categories" to add it).', ['category' => $data['category']]);
                    } else {
                        if ($apply) {
                            // Undone below if the row turns out to have errors.
                            $this->db->pdo()->exec('SAVEPOINT csv_import_row');
                        }
                        $categoryId = $apply ? $this->categories->create($data['category']) : --$fakeId;
                        $newCategories[$catKey] = $categoryId;
                        $createdCategory = $catKey;
                        $counts['new_categories']++;
                        $entry['changes'][] = $this->t('new category "{category}"', ['category' => $data['category']]);
                    }
                }
            }

            $variant = $data['sku'] !== '' ? $existing[mb_strtolower($data['sku'])] ?? null : null;
            if ($entry['errors'] === []) {
                match (true) {
                    $data['sku'] === ''  => $this->planProductOnly($entry, $data, $categoryId, $productsById, $updatedProducts, $apply),
                    $variant !== null    => $this->planUpdate($entry, $data, $variant, $categoryId, $updatedProducts, $apply),
                    default              => $this->planCreate($entry, $data, $categoryId, $productsById, $newProducts, $fakeId, $apply),
                };
            }

            if ($entry['errors'] !== []) {
                $entry['action'] = 'error';
                if ($createdCategory !== null) {
                    // A skipped row leaves no category behind (a later valid row can still create it).
                    // The row writes nothing else before its checks pass, so only the category is undone.
                    if ($apply) {
                        $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT csv_import_row');
                    }
                    unset($newCategories[$createdCategory]);
                    $counts['new_categories']--;
                    $entry['changes'] = [];
                }
            }
            $counts[$entry['action']]++;
            $report[] = $entry;
        }

        return ['rows' => $report, 'counts' => $counts];
    }

    /**
     * @param array<string, mixed>              $entry
     * @param array<string, mixed>              $data
     * @param array<string, mixed>              $variant existing variant + product fields
     * @param array<int, array<string, mixed>>  $updatedProducts
     */
    private function planUpdate(array &$entry, array $data, array $variant, ?int $categoryId, array &$updatedProducts, bool $apply): void
    {
        $productId = (int) $variant['product_id'];
        if ($data['product_id'] !== null && $data['product_id'] !== $productId) {
            $entry['errors'][] = $this->t('SKU "{sku}" belongs to product #{product}, not #{given}.', ['sku' => $data['sku'], 'product' => $productId, 'given' => $data['product_id']]);

            return;
        }
        $entry['name'] = $entry['name'] !== '' ? $entry['name'] : (string) $variant['product_name'];

        // Variant fields.
        $current = [
            'sku'       => (string) $variant['sku'],
            'label'     => $variant['label'] !== null ? (string) $variant['label'] : null,
            'unit'      => $variant['unit'] !== null ? (string) $variant['unit'] : null,
            'price'     => round((float) $variant['price'], 2),
            'stock'     => (int) $variant['stock'],
            'is_active' => (int) $variant['is_active'],
        ];
        $next = $current;
        foreach (['label', 'unit'] as $field) {
            if ($data[$field] !== null) {
                $next[$field] = $data[$field] === false ? null : $data[$field];
            }
        }
        foreach (['price', 'stock', 'is_active'] as $field) {
            if ($data[$field] !== null) {
                $next[$field] = $data[$field];
            }
        }
        $variantChanges = $this->diff($current, $next, ['label', 'unit', 'price', 'stock', 'is_active']);

        $productChanges = $this->productChanges($productId, [
            'name'              => (string) $variant['product_name'],
            'category_id'       => (int) $variant['category_id'],
            'short_description' => (string) $variant['short_description'],
            'status'            => (string) $variant['import_status'],
        ], $data, $categoryId, $updatedProducts, $apply);

        $entry['changes'] = [...$entry['changes'], ...$variantChanges, ...$productChanges];
        $entry['action'] = $entry['changes'] === [] ? 'unchanged' : 'update';
        if ($apply && $variantChanges !== []) {
            $this->variants->update((int) $variant['id'], $productId, $next);
        }
    }

    /**
     * A row without SKU but with product_id: updates only the product's fields
     * (the export writes such a row for a product that has no variants).
     *
     * @param array<string, mixed>             $entry
     * @param array<string, mixed>             $data
     * @param array<int, array<string, mixed>> $productsById
     * @param array<int, array<string, mixed>> $updatedProducts
     */
    private function planProductOnly(array &$entry, array $data, ?int $categoryId, array $productsById, array &$updatedProducts, bool $apply): void
    {
        $product = $productsById[(int) $data['product_id']] ?? null;
        if ($product === null) {
            $entry['errors'][] = $this->t('Product #{id} does not exist.', ['id' => $data['product_id']]);

            return;
        }
        $entry['name'] = $entry['name'] !== '' ? $entry['name'] : (string) $product['name'];
        $changes = $this->productChanges((int) $product['id'], [
            'name'              => (string) $product['name'],
            'category_id'       => (int) $product['category_id'],
            'short_description' => (string) $product['short_description'],
            'status'            => (string) $product['import_status'],
        ], $data, $categoryId, $updatedProducts, $apply);
        $entry['changes'] = [...$entry['changes'], ...$changes];
        $entry['action'] = $entry['changes'] === [] ? 'unchanged' : 'update';
    }

    /**
     * Compares (and with $apply, writes) a product's name, category, short
     * description and status, against what earlier rows of the file already set.
     *
     * @param array{name: string, category_id: int, short_description: string, status: string} $stored
     * @param array<string, mixed>             $data
     * @param array<int, array<string, mixed>> $updatedProducts
     * @return list<string>
     */
    private function productChanges(int $productId, array $stored, array $data, ?int $categoryId, array &$updatedProducts, bool $apply): array
    {
        $product = ($updatedProducts[$productId] ?? []) + $stored;
        $next = $product;
        if ($data['name'] !== null) {
            $next['name'] = $data['name'];
        }
        if ($categoryId !== null) {
            $next['category_id'] = $categoryId;
        }
        if ($data['short_description'] !== null) {
            $next['short_description'] = $data['short_description'];
        }
        if ($data['status'] !== null) {
            $next['status'] = $data['status'];
        }
        $changes = $this->diff($product, $next, ['name', 'category_id', 'short_description', 'status']);
        $updatedProducts[$productId] = $next;

        if ($apply && $changes !== []) {
            $this->products->update($productId, $next);
            if ($next['status'] !== $product['status']) {
                $this->products->setStatus([$productId], $next['status']);
            }
        }

        return $changes;
    }

    /**
     * @param array<string, mixed>             $entry
     * @param array<string, mixed>             $data
     * @param array<int, array<string, mixed>> $productsById
     * @param array<string, int>               $newProducts
     */
    private function planCreate(array &$entry, array $data, ?int $categoryId, array $productsById, array &$newProducts, int &$fakeId, bool $apply): void
    {
        if ($data['price'] === null || $data['price'] <= 0) {
            $entry['errors'][] = $this->t('A new variant needs a price above 0.');
        }
        $variant = [
            'sku'       => $data['sku'],
            'label'     => is_string($data['label']) ? $data['label'] : null,
            'unit'      => is_string($data['unit']) ? $data['unit'] : null,
            'price'     => (float) $data['price'],
            'stock'     => $data['stock'] ?? 0,
            'is_active' => $data['is_active'] ?? 1,
        ];

        // A new variant of an existing product.
        if ($data['product_id'] !== null) {
            $product = $productsById[$data['product_id']] ?? null;
            if ($product === null) {
                $entry['errors'][] = $this->t('Product #{id} does not exist (leave product_id empty to create a new product).', ['id' => $data['product_id']]);

                return;
            }
            if ($entry['errors'] !== []) {
                return;
            }
            $entry['name'] = (string) $product['name'];
            $entry['action'] = 'create_variant';
            $entry['changes'][] = $this->t('new variant of product #{id}', ['id' => $data['product_id']]);
            if ($apply) {
                $this->variants->create(['product_id' => (int) $data['product_id']] + $variant);
            }

            return;
        }

        // A new product (rows with the same name + category share it).
        if ($data['name'] === null) {
            $entry['errors'][] = $this->t('Name is required for a new product.');
        }
        if ($categoryId === null) {
            $entry['errors'][] = $this->t('Category is required for a new product.');
        }
        if ($entry['errors'] !== []) {
            return;
        }
        $groupKey = mb_strtolower($data['name']) . '|' . $categoryId;
        if (isset($newProducts[$groupKey])) {
            $entry['action'] = 'create_variant';
            $entry['changes'][] = $this->t('new variant of the new product above');
            if ($apply) {
                $this->variants->create(['product_id' => $newProducts[$groupKey]] + $variant);
            }

            return;
        }
        if ($data['short_description'] === null) {
            $entry['errors'][] = $this->t('Short description is required for a new product.');

            return;
        }

        $entry['action'] = 'create_product';
        $entry['changes'][] = $this->t('new product (status "created")');
        if (!$apply) {
            $newProducts[$groupKey] = --$fakeId;

            return;
        }
        $productId = $this->products->create([
            'category_id'       => $categoryId,
            'name'              => $data['name'],
            'short_description' => $data['short_description'],
            'long_description'  => '<p>' . htmlspecialchars($data['short_description'], ENT_QUOTES, 'UTF-8') . '</p>',
        ]);
        $newProducts[$groupKey] = $productId;
        $this->variants->create(['product_id' => $productId] + $variant);
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param list<string>         $fields
     * @return list<string> "price 10.00 → 12.00"
     */
    private function diff(array $before, array $after, array $fields): array
    {
        $changes = [];
        foreach ($fields as $field) {
            $old = $before[$field];
            $new = $after[$field];
            if (is_float($old) || is_float($new) ? abs((float) $old - (float) $new) >= 0.005 : $old !== $new) {
                $empty = $this->t('(empty)');
                $show = static fn (mixed $v): string => match (true) {
                    $v === null   => $empty,
                    is_float($v)  => number_format($v, 2, '.', ''),
                    default       => mb_strimwidth((string) $v, 0, 40, '…'),
                };
                if ($field === 'category_id') {
                    $changes[] = $this->t('category changed');
                    continue;
                }
                $label = match ($field) {
                    'label'             => $this->t('label'),
                    'unit'              => $this->t('unit'),
                    'price'             => $this->t('price'),
                    'stock'             => $this->t('stock'),
                    'is_active'         => $this->t('active'),
                    'name'              => $this->t('name'),
                    'short_description' => $this->t('short description'),
                    'status'            => $this->t('status'),
                    default             => $field,
                };
                $changes[] = $this->t('{field} {old} → {new}', ['field' => $label, 'old' => $show($old), 'new' => $show($new)]);
            }
        }

        return $changes;
    }

    /** "1.234,50" / "1,234.50" / "12,5" / "€ 12.50" → float, or null. */
    public static function decimal(string $value): ?float
    {
        $value = (string) preg_replace('/[^\d,.\-]/', '', $value);
        if ($value === '' || $value === '-') {
            return null;
        }
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $value = str_replace(['.', ','], ['', '.'], $value); // comma is the decimal separator
        } else {
            $value = str_replace(',', '', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
