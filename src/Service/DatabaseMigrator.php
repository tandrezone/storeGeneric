<?php

declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\Database;
use App\Support\Paths;
use PDO;
use RuntimeException;

/**
 * Applies database/migrations/*.sql in filename order and remembers what ran
 * in the schema_migrations table, so each file runs once. Used by
 * `bin/console db:migrate`.
 *
 *  - Empty database: schema.sql is loaded (it already contains every
 *    migration) and all migration files are recorded as applied.
 *  - Existing database that was migrated by hand (no schema_migrations yet):
 *    migrations up to the baseline are recorded without running. The baseline
 *    is detected (012 when the `settings` table exists) or given explicitly;
 *    the rest then run normally. Migrations 013 and later can safely re-run.
 *
 * MariaDB commits every DDL statement, so a migration is not transactional:
 * if a file fails halfway, fix the cause and run the command again.
 */
final class DatabaseMigrator
{
    public const TABLE = 'schema_migrations';

    /** Newest migration that creates the `settings` table; databases having it were migrated at least this far. */
    private const SETTINGS_MIGRATION = 12;

    public function __construct(
        private readonly Database $db,
        private readonly Paths $paths,
    ) {
    }

    /** @return list<string> migration file names (e.g. "013_order_stock_tracking.sql"), oldest first */
    public function files(): array
    {
        $files = array_map('basename', glob($this->paths->root . '/database/migrations/*.sql') ?: []);
        sort($files);

        return $files;
    }

    /** Leading number of a migration file name ("013_x.sql" → 13); 0 when it has none. */
    public static function number(string $file): int
    {
        return preg_match('/^(\d+)_/', $file, $match) === 1 ? (int) $match[1] : 0;
    }

    /** @return list<string> migrations recorded as applied (empty when nothing is tracked yet) */
    public function applied(): array
    {
        if (!$this->tableExists(self::TABLE)) {
            return [];
        }
        $names = $this->db->pdo()->query('SELECT filename FROM ' . self::TABLE . ' ORDER BY filename')->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_map('strval', $names));
    }

    /** @return list<string> migration files still to run, given what is recorded (does not look at the baseline) */
    public function pending(): array
    {
        return array_values(array_diff($this->files(), $this->applied()));
    }

    /** True when the database has no tables of the store yet. */
    public function isEmpty(): bool
    {
        return $this->tables() === [];
    }

    /** True when the database has store tables but no migration history (set up by hand). */
    public function isUntracked(): bool
    {
        return !$this->isEmpty() && $this->applied() === [];
    }

    /**
     * The baseline to assume for an untracked database: --baseline if given,
     * else 12 when the `settings` table exists, else null (can't tell).
     */
    public function detectBaseline(?int $explicit = null): ?int
    {
        if ($explicit !== null) {
            return $explicit;
        }

        return $this->tableExists('settings') ? self::SETTINGS_MIGRATION : null;
    }

    /**
     * Brings the database up to date.
     *
     * @param ?callable(string): void $log      progress messages
     * @param ?int                    $baseline for an untracked database: last migration number it already has
     * @return array{loaded_schema: bool, baselined: list<string>, applied: list<string>}
     */
    public function migrate(?callable $log = null, ?int $baseline = null, bool $dryRun = false): array
    {
        $log ??= static function (string $message): void {
        };
        $result = ['loaded_schema' => false, 'baselined' => [], 'applied' => []];

        if (!$dryRun) {
            $this->ensureTable();
        }

        if ($this->isEmpty()) {
            $log('Empty database: loading schema.sql (it already contains every migration).');
            if (!$dryRun) {
                $this->runSqlFile($this->paths->root . '/database/schema.sql');
                $this->record($this->files());
            }
            $result['loaded_schema'] = true;
            $result['baselined'] = $this->files();

            return $result;
        }

        if ($this->applied() === []) {
            $baseline = $this->detectBaseline($baseline);
            if ($baseline === null) {
                throw new RuntimeException(
                    'This database has tables but no migration history, and it is older than migration 012. '
                    . 'Say which migration it already has, e.g. --baseline=010 (migrations up to that number are recorded, not run).'
                );
            }
            $already = array_values(array_filter($this->files(), static fn (string $file): bool => self::number($file) <= $baseline));
            $log(sprintf('No migration history yet: assuming migrations up to %03d are already applied (%d file(s)).', $baseline, count($already)));
            if (!$dryRun) {
                $this->record($already);
            }
            $result['baselined'] = $already;
        }

        $done = $dryRun ? $result['baselined'] : $this->applied();
        foreach ($this->files() as $file) {
            if (in_array($file, $done, true)) {
                continue;
            }
            $log(($dryRun ? 'would apply ' : 'applying ') . $file);
            if (!$dryRun) {
                try {
                    $this->runSqlFile($this->paths->root . '/database/migrations/' . $file);
                } catch (\PDOException $e) {
                    throw new RuntimeException("{$file} failed: " . $e->getMessage() . ' — fix the cause and run db:migrate again.', 0, $e);
                }
                $this->record([$file]);
            }
            $result['applied'][] = $file;
        }

        return $result;
    }

    /** @return list<string> tables in the database, not counting the history table */
    private function tables(): array
    {
        $tables = $this->db->pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_filter(array_map('strval', $tables), static fn (string $table): bool => $table !== self::TABLE));
    }

    private function tableExists(string $table): bool
    {
        return in_array($table, array_map('strval', $this->db->pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    private function ensureTable(): void
    {
        $this->db->pdo()->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
            filename VARCHAR(190) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB');
    }

    /** @param list<string> $files */
    private function record(array $files): void
    {
        $stmt = $this->db->pdo()->prepare('INSERT IGNORE INTO ' . self::TABLE . ' (filename) VALUES (?)');
        foreach ($files as $file) {
            $stmt->execute([$file]);
        }
    }

    /** Runs a SQL file against the configured database, ignoring its CREATE DATABASE / USE lines. */
    private function runSqlFile(string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Could not read ' . $path);
        }
        $sql = preg_replace('/^\s*(CREATE\s+DATABASE|USE)\b[^;]*;/im', '', $sql) ?? $sql;
        if (trim($sql) !== '') {
            $this->db->pdo()->exec($sql);
        }
    }
}
