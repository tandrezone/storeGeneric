<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Infrastructure\Database;
use App\Repository\CategoryRepository;
use App\Repository\ProductCsvRepository;
use App\Repository\ProductRepository;
use App\Repository\VariantRepository;
use App\Service\ProductCsvImporter;
use App\Support\Config;
use RuntimeException;
use Tests\TestCase;

/** Parsing and validation only — the database side is in tests/Integration. */
final class ProductCsvImporterTest extends TestCase
{
    private ProductCsvImporter $importer;

    public function setUp(): void
    {
        $db = new Database(Config::fromArray([])); // never connects in these tests
        $this->importer = new ProductCsvImporter(
            $db,
            new ProductCsvRepository($db),
            new ProductRepository($db),
            new VariantRepository($db),
            new CategoryRepository($db),
        );
    }

    public function testReadsRowsByHeaderName(): void
    {
        $rows = $this->importer->readRows("\xEF\xBB\xBFSKU,Price,Short Description\nA-1,12.50,Nice\n\nA-2,3,\n");
        $this->assertCount(2, $rows);
        $this->assertSame(['_line' => 2, 'sku' => 'A-1', 'price' => '12.50', 'short_description' => 'Nice'], $rows[0]);
        $this->assertSame(4, $rows[1]['_line'], 'line numbers count the skipped blank line');
    }

    public function testHeaderErrors(): void
    {
        $this->assertThrows(RuntimeException::class, fn () => $this->importer->readRows(''), 'empty');
        $this->assertThrows(RuntimeException::class, fn () => $this->importer->readRows("name,price\nTea,1\n"), '"sku"');
        $this->assertThrows(RuntimeException::class, fn () => $this->importer->readRows("sku,colour\nA,red\n"), 'Unknown column(s): colour');
        $this->assertThrows(RuntimeException::class, fn () => $this->importer->readRows("sku,sku\nA,B\n"), 'twice');
        $this->assertThrows(RuntimeException::class, fn () => $this->importer->readRows("sku,price\n"), 'no rows');
    }

    public function testRowLimit(): void
    {
        $text = "sku\n" . str_repeat("A\n", ProductCsvImporter::MAX_ROWS + 1);
        $this->assertThrows(RuntimeException::class, fn () => $this->importer->readRows($text), 'too many rows');
    }

    public function testNormalizeValidRow(): void
    {
        [$data, $errors] = $this->importer->normalize([
            'product_id' => '12', 'name' => ' Tea ', 'category' => 'Drinks', 'status' => 'Approved',
            'sku' => 'T-1', 'label' => '', 'unit' => 'box', 'price' => '1.234,50', 'stock' => '7', 'active' => 'yes',
        ]);
        $this->assertSame([], $errors);
        $this->assertSame(12, $data['product_id']);
        $this->assertSame('Tea', $data['name']);
        $this->assertSame('approved', $data['status']);
        $this->assertFalse($data['label'], 'empty label clears it');
        $this->assertSame('box', $data['unit']);
        $this->assertSame(1234.5, $data['price']);
        $this->assertSame(7, $data['stock']);
        $this->assertSame(1, $data['is_active']);
        $this->assertNull($data['short_description'], 'absent column = unchanged');
    }

    public function testNormalizeReportsEveryProblem(): void
    {
        [, $errors] = $this->importer->normalize([
            'sku' => '', 'status' => 'live', 'price' => 'cheap', 'stock' => '-2', 'active' => 'maybe',
            'name' => str_repeat('n', 181),
        ]);
        $this->assertCount(6, $errors);
        $this->assertContains('SKU is required.', $errors);

        [, $errors] = $this->importer->normalize(['product_id' => 'x', 'sku' => '']);
        $this->assertSame(['product_id "x" is not a valid id.'], $errors, 'a product row without SKU is allowed');
    }

    public function testDecimalParsing(): void
    {
        $this->assertSame(12.5, ProductCsvImporter::decimal('12.50'));
        $this->assertSame(12.5, ProductCsvImporter::decimal('12,50'));
        $this->assertSame(1234.5, ProductCsvImporter::decimal('1,234.50'));
        $this->assertSame(1234.5, ProductCsvImporter::decimal('1.234,50'));
        $this->assertSame(9.99, ProductCsvImporter::decimal('€ 9.99'));
        $this->assertNull(ProductCsvImporter::decimal('abc'));
    }
}
