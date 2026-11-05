<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Service\CsvExport;
use App\Service\ProductCsvImporter;
use App\Support\Csv;
use Tests\IntegrationTestCase;

final class ProductCsvTest extends IntegrationTestCase
{
    public function testDryRunWritesNothingAndApplyUpdates(): void
    {
        $sku = $this->sku('U');
        $ids = $this->makeProduct($sku, 10.0, 5, 'Old name');
        $importer = $this->get(ProductCsvImporter::class);
        $rows = $importer->readRows("sku,name,price,stock,label,active,status\n{$sku},New name,12.5,9,,0,update\n");

        $preview = $importer->run($rows, false, false);
        $this->assertSame('update', $preview['rows'][0]['action']);
        $this->assertSame(1, $preview['counts']['update']);
        $this->assertSame(5, $this->stockOf($ids['variant']), 'the dry run changed nothing');

        $report = $importer->run($rows, false, true);
        $this->assertTrue($report['applied']);
        $variant = $this->pdo()->query('SELECT * FROM product_variants WHERE id = ' . $ids['variant'])->fetch();
        $this->assertEquals(12.5, $variant['price']);
        $this->assertEquals(9, $variant['stock']);
        $this->assertNull($variant['label'], 'empty label cleared');
        $this->assertEquals(0, $variant['is_active']);
        $product = $this->pdo()->query('SELECT * FROM products WHERE id = ' . $ids['product'])->fetch();
        $this->assertSame('New name', $product['name']);
        $this->assertSame('update', $product['import_status']);

        $again = $importer->run($rows, false, false);
        $this->assertSame('unchanged', $again['rows'][0]['action']);
    }

    public function testCreatesProductsVariantsAndCategories(): void
    {
        $importer = $this->get(ProductCsvImporter::class);
        $category = 'Imported ' . bin2hex(random_bytes(3));
        $a = $this->sku('N');
        $b = $this->sku('N');
        $csv = "name,category,short_description,sku,label,unit,price,stock\n"
            . "Imported tea,{$category},Fresh,{$a},100,g,4.50,10\n"
            . "Imported tea,{$category},Fresh,{$b},250,g,\"9,90\",3\n";

        $blocked = $importer->run($importer->readRows($csv), false, false);
        $this->assertSame(2, $blocked['counts']['error'], 'unknown category without the checkbox');
        $this->assertStringContainsString('does not exist', $blocked['rows'][0]['errors'][0]);

        $report = $importer->run($importer->readRows($csv), true, true);
        $this->assertSame(1, $report['counts']['create_product']);
        $this->assertSame(1, $report['counts']['create_variant']);
        $this->assertSame(1, $report['counts']['new_categories']);

        $rows = $this->pdo()->query("
            SELECT p.id, p.import_status, p.long_description, v.sku, v.price FROM products p
            JOIN categories c ON c.id = p.category_id JOIN product_variants v ON v.product_id = p.id
            WHERE c.name = " . $this->pdo()->quote($category) . ' ORDER BY v.id')->fetchAll();
        $this->assertCount(2, $rows);
        $this->assertSame($rows[0]['id'], $rows[1]['id'], 'both variants on one new product');
        $this->assertSame('created', $rows[0]['import_status']);
        $this->assertSame('<p>Fresh</p>', $rows[0]['long_description']);
        $this->assertEquals(9.9, $rows[1]['price']);
    }

    public function testRowErrorsAreReportedAndSkipped(): void
    {
        $existing = $this->makeProduct($this->sku('E'), 10.0, 5);
        $importer = $this->get(ProductCsvImporter::class);
        $okSku = $this->sku('E');
        $csv = "product_id,sku,price,stock\n"
            . "{$existing['product']},{$okSku},3.00,1\n"   // new variant of an existing product
            . "999999,{$this->sku('E')},3.00,1\n"         // unknown product
            . "{$existing['product']},{$okSku},4.00,1\n"   // duplicate SKU in the file
            . ",{$this->sku('E')},,\n";                     // new product without name/price

        $report = $importer->run($importer->readRows($csv), false, true);
        $this->assertSame('create_variant', $report['rows'][0]['action']);
        $this->assertSame(['error', 'error', 'error'], array_column(array_slice($report['rows'], 1), 'action'));
        $this->assertSame(3, $report['rows'][1]['line']);
        $this->assertStringContainsString('already appears on line 2', $report['rows'][2]['errors'][0]);

        $count = $this->pdo()->query('SELECT COUNT(*) FROM product_variants WHERE product_id = ' . $existing['product'])->fetchColumn();
        $this->assertEquals(2, $count);
    }

    public function testFailedRowLeavesNoNewCategory(): void
    {
        $importer = $this->get(ProductCsvImporter::class);
        $orphan = 'Orphan ' . bin2hex(random_bytes(3));
        $shared = 'Shared ' . bin2hex(random_bytes(3));
        $csv = "name,category,short_description,sku,price,stock\n"
            . "Bad tea,{$orphan},Fresh,{$this->sku('C')},0,1\n"   // price 0: fails after the category step
            . "Bad tea,{$shared},,{$this->sku('C')},2.00,1\n"      // no short description
            . "Good tea,{$shared},Fresh,{$this->sku('C')},2.00,1\n"; // creates the category after all

        $preview = $importer->run($importer->readRows($csv), true, false);
        $this->assertSame(1, $preview['counts']['new_categories']);

        $report = $importer->run($importer->readRows($csv), true, true);
        $this->assertSame(['error', 'error', 'create_product'], array_column($report['rows'], 'action'));
        $this->assertSame(1, $report['counts']['new_categories']);

        $pdo = $this->pdo();
        $count = static fn (string $name): int => (int) $pdo->query('SELECT COUNT(*) FROM categories WHERE name = ' . $pdo->quote($name))->fetchColumn();
        $this->assertSame(0, $count($orphan), 'no category for a row that failed');
        $this->assertSame(1, $count($shared));
    }

    public function testExportRoundTripsUnchanged(): void
    {
        $ids = $this->makeProduct($this->sku('X'), 7.25, 4, '=HYPERLINK("x") tea');
        $handle = $this->get(CsvExport::class)->products(null);
        rewind($handle);
        $text = (string) stream_get_contents($handle);

        $this->assertTrue(str_starts_with($text, Csv::BOM));
        $this->assertStringContainsString("'=HYPERLINK", $text, 'formula neutralised');
        $this->assertStringContainsString(',7.25,,,,4,0,1', $text);

        $importer = $this->get(ProductCsvImporter::class);
        $report = $importer->run($importer->readRows($text), false, false);
        $this->assertSame(0, $report['counts']['error'] + $report['counts']['update'] + $report['counts']['create_product'], 'export → import changes nothing');

        // Filtered export: only approved + low stock.
        $this->pdo()->exec('UPDATE products SET import_status = \'invalid\' WHERE id = ' . $ids['product']);
        $handle = $this->get(CsvExport::class)->products('approved');
        rewind($handle);
        $this->assertStringNotContainsString('HYPERLINK', (string) stream_get_contents($handle));
    }

    public function testOrderExportHonoursFilters(): void
    {
        $ids = $this->makeProduct($this->sku('O'), 5.0, 10);
        $order = $this->placeOrder($ids['variant'], 1, 5.0);

        $handle = $this->get(CsvExport::class)->orders(['q' => $order['order_number']]);
        rewind($handle);
        $rows = Csv::parse((string) stream_get_contents($handle));
        $this->assertCount(2, $rows);
        $this->assertSame('order_number', $rows[0][0]);
        $this->assertSame($order['order_number'], $rows[1][0]);
        $this->assertContains('total', $rows[0]);

        $handle = $this->get(CsvExport::class)->orders(['q' => $order['order_number'], 'from' => '2000-01-01', 'to' => '2000-01-02']);
        rewind($handle);
        $this->assertCount(1, Csv::parse((string) stream_get_contents($handle)), 'header only');

        $handle = $this->get(CsvExport::class)->orderItems(['q' => $order['order_number']]);
        rewind($handle);
        $items = Csv::parse((string) stream_get_contents($handle));
        $this->assertCount(2, $items);
        $this->assertSame($order['order_number'], $items[1][0]);
    }

    public function testSaleAndWeightColumnsImportExportAndClear(): void
    {
        $sku = $this->sku('S');
        $ids = $this->makeProduct($sku, 10.0, 5);
        $importer = $this->get(ProductCsvImporter::class);
        $row = fn (string $cells) => $importer->readRows("sku,price,sale_price,sale_starts,sale_ends,weight_grams\n{$sku},{$cells}\n");
        $variant = fn (): array => $this->pdo()->query('SELECT price, sale_price, sale_starts_at, sale_ends_at, weight_grams FROM product_variants WHERE id = ' . (int) $ids['variant'])->fetch();

        $report = $importer->run($row('10.00,7.50,2026-12-01,2026-12-24 18:00,350'), false, true);
        $this->assertSame('update', $report['rows'][0]['action'], implode('; ', $report['rows'][0]['errors']));
        $v = $variant();
        $this->assertEquals(7.5, $v['sale_price']);
        $this->assertSame('2026-12-01 00:00:00', $v['sale_starts_at']);
        $this->assertSame('2026-12-24 18:00:00', $v['sale_ends_at']);
        $this->assertEquals(350, $v['weight_grams']);

        // Export → import is a no-op.
        $handle = $this->get(CsvExport::class)->products(null);
        rewind($handle);
        $text = (string) stream_get_contents($handle);
        $this->assertTrue(preg_match('/7\\.50,"?2026-12-01 00:00:00"?,"?2026-12-24 18:00:00"?/', $text) === 1, 'sale columns exported');
        $again = $importer->run($importer->readRows($text), false, false);
        $this->assertSame(0, $again['counts']['error'] + $again['counts']['update'] + $again['counts']['create_product']);

        // A file without the sale columns leaves the sale alone; empty cells clear it.
        $importer->run($importer->readRows("sku,price\n{$sku},11.00\n"), false, true);
        $this->assertEquals(7.5, $variant()['sale_price']);
        $this->assertEquals(11.0, $variant()['price']);
        $importer->run($row('11.00,,,,'), false, true);
        $v = $variant();
        $this->assertNull($v['sale_price']);
        $this->assertNull($v['sale_starts_at']);
        $this->assertEquals(350, $v['weight_grams'], 'empty weight = unchanged');
    }

    public function testSaleValidationInCsv(): void
    {
        $sku = $this->sku('V');
        $this->makeProduct($sku, 10.0, 5);
        $importer = $this->get(ProductCsvImporter::class);
        foreach (['10.00,12.00,,,' => 'lower', '10.00,abc,,,' => 'valid amount', '10.00,5,not-a-date,,' => 'not a date', '10.00,5,2026-12-10,2026-12-01,' => 'end after', '10.00,,,,-4' => 'whole number'] as $cells => $message) {
            $report = $importer->run($importer->readRows("sku,price,sale_price,sale_starts,sale_ends,weight_grams\n{$sku},{$cells}\n"), false, true);
            $this->assertSame('error', $report['rows'][0]['action'], $cells);
            $this->assertStringContainsString($message, implode(' ', $report['rows'][0]['errors']), $cells);
        }
        $this->assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM product_variants WHERE sku = '$sku' AND sale_price IS NOT NULL")->fetchColumn());
    }

    public function testNewVariantCanComeWithASaleAndWeight(): void
    {
        $existing = $this->makeProduct($this->sku('E'), 10.0, 5);
        $sku = $this->sku('N');
        $importer = $this->get(ProductCsvImporter::class);
        $csv = "product_id,sku,price,sale_price,weight_grams,stock\n{$existing['product']},{$sku},20.00,15.00,1200,3\n";
        $report = $importer->run($importer->readRows($csv), false, true);
        $this->assertSame('create_variant', $report['rows'][0]['action'], implode('; ', $report['rows'][0]['errors']));
        $v = $this->pdo()->query("SELECT sale_price, weight_grams FROM product_variants WHERE sku = '$sku'")->fetch();
        $this->assertEquals(15.0, $v['sale_price']);
        $this->assertEquals(1200, $v['weight_grams']);
    }
}
