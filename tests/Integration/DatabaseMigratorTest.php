<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Service\DatabaseMigrator;
use Tests\IntegrationTestCase;

/**
 * The test database was rebuilt by db:reimport, so it has every table but no
 * schema_migrations history — the "set up by hand" case.
 */
final class DatabaseMigratorTest extends IntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->pdo()->exec('DROP TABLE IF EXISTS ' . DatabaseMigrator::TABLE);
    }

    public function tearDown(): void
    {
        $this->pdo()->exec('DROP TABLE IF EXISTS ' . DatabaseMigrator::TABLE);
    }

    public function testDryRunChangesNothing(): void
    {
        $migrator = $this->get(DatabaseMigrator::class);

        $result = $migrator->migrate(null, null, true);

        $this->assertTrue($result['applied'] !== [], 'migrations after the detected baseline are listed');
        $this->assertSame([], $migrator->applied());
        $this->assertSame(0, (int) $this->pdo()->query("SHOW TABLES LIKE '" . DatabaseMigrator::TABLE . "'")->rowCount());
    }

    public function testBaselinesAHandMigratedDatabaseThenRunsTheRestOnce(): void
    {
        $migrator = $this->get(DatabaseMigrator::class);
        $this->assertTrue($migrator->isUntracked());

        $first = $migrator->migrate();
        $this->assertTrue(!$first['loaded_schema']);
        $this->assertTrue(in_array('012_settings.sql', $first['baselined'], true), 'migrations up to 012 are only recorded');
        $this->assertTrue(!in_array('013_order_stock_tracking.sql', $first['baselined'], true));
        $this->assertTrue(in_array('013_order_stock_tracking.sql', $first['applied'], true), '013 and later run (and are safe to re-run)');
        $this->assertSame($migrator->files(), $migrator->applied(), 'every file is recorded');

        $second = $migrator->migrate();
        $this->assertSame([], $second['applied']);
        $this->assertSame([], $second['baselined']);
        $this->assertSame([], $migrator->pending());
    }

    public function testExplicitBaselineSkipsOlderMigrations(): void
    {
        $migrator = $this->get(DatabaseMigrator::class);

        $result = $migrator->migrate(null, 27);

        $this->assertSame($migrator->files(), $result['baselined']);
        $this->assertSame([], $result['applied']);
    }
}
