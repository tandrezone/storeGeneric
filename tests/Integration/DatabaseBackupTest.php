<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Service\DatabaseBackup;
use Tests\IntegrationTestCase;

final class DatabaseBackupTest extends IntegrationTestCase
{
    private string $dir;

    public function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/store-backup-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    public function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testDumpAndRestoreRoundTrip(): void
    {
        foreach ([false, true] as $gzip) {
            if ($gzip && !function_exists('gzopen')) {
                continue;
            }
            $tricky = "Quote ' backslash \\ semicolon; newline\nemoji 🌵 -- not a comment";
            $ids = $this->makeProduct($this->sku('B'), 3.33, 7, $tricky);
            $backup = $this->get(DatabaseBackup::class);
            $file = $this->dir . '/' . $backup->fileName('store_test', $gzip);

            $stats = $backup->dump($file, $gzip);
            $this->assertTrue($stats['tables'] >= 10, 'every table dumped');
            $this->assertTrue($stats['rows'] > 0);
            $this->assertSame($gzip ? "\x1f\x8b" : '--', substr((string) file_get_contents($file), 0, 2));

            // Damage the data, then restore.
            $this->pdo()->exec('UPDATE product_variants SET stock = 0, price = 1 WHERE id = ' . $ids['variant']);
            $this->pdo()->exec('DELETE FROM shipping_methods');
            $before = (int) $this->pdo()->query('SELECT COUNT(*) FROM products')->fetchColumn();

            $statements = $backup->restore($file);
            $this->assertTrue($statements > 10);

            $this->assertSame(7, $this->stockOf($ids['variant']));
            $this->assertSame($tricky, $this->pdo()->query('SELECT name FROM products WHERE id = ' . $ids['product'])->fetchColumn());
            $this->assertTrue((int) $this->pdo()->query('SELECT COUNT(*) FROM shipping_methods')->fetchColumn() >= 1);
            $this->assertSame($before, (int) $this->pdo()->query('SELECT COUNT(*) FROM products')->fetchColumn());
            $this->assertEquals(1, $this->pdo()->query('SELECT @@foreign_key_checks')->fetchColumn(), 'checks back on');
        }
    }

    public function testPruneKeepsTheNewest(): void
    {
        $backup = $this->get(DatabaseBackup::class);
        $names = [];
        foreach ([1, 2, 3, 4] as $day) {
            $name = $backup->fileName('shop', $day % 2 === 0, mktime(3, 0, 0, 1, $day, 2026));
            touch($this->dir . '/' . $name);
            $names[] = $name;
        }
        touch($this->dir . '/' . DatabaseBackup::uploadsName($names[0]));
        touch($this->dir . '/unrelated.sql');

        $deleted = $backup->prune($this->dir, 2);

        $this->assertCount(3, $deleted);
        $this->assertContains($names[0], $deleted);
        $this->assertContains($names[1], $deleted);
        $this->assertContains(DatabaseBackup::uploadsName($names[0]), $deleted);
        $this->assertTrue(is_file($this->dir . '/' . $names[2]));
        $this->assertTrue(is_file($this->dir . '/' . $names[3]));
        $this->assertTrue(is_file($this->dir . '/unrelated.sql'));
    }
}
