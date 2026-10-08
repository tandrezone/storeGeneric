<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\DatabaseMigrator;
use Tests\TestCase;

final class DatabaseMigratorTest extends TestCase
{
    public function testNumberIsTheLeadingDigits(): void
    {
        $this->assertSame(13, DatabaseMigrator::number('013_order_stock_tracking.sql'));
        $this->assertSame(27, DatabaseMigrator::number('027_locales.sql'));
        $this->assertSame(0, DatabaseMigrator::number('notes.sql'));
    }

    public function testMigrationFilesAreNumberedAndUnique(): void
    {
        $numbers = [];
        foreach (glob(dirname(__DIR__, 3) . '/database/migrations/*.sql') ?: [] as $path) {
            $number = DatabaseMigrator::number(basename($path));
            $this->assertTrue($number > 0, basename($path) . ' starts with a number');
            $this->assertTrue(!isset($numbers[$number]), "migration number {$number} is used once");
            $numbers[$number] = true;
        }
        $this->assertTrue($numbers !== [], 'there are migrations');
    }
}
