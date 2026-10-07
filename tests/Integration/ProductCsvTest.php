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
        $this->assertStringContainsString(',7.25,4,1', $text);

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
}
