<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Command;
use App\Console\Input;
use App\Console\Output;
use App\Infrastructure\Database;
use App\Support\Config;
use App\Support\Paths;
use PDO;
use PDOException;
use RuntimeException;

/**
 * DESTRUCTIVE local reset: drops every table, then applies database/schema.sql
 * and every database/migrations/*.sql in filename order. Requires --force.
 */
final class ReimportDatabaseCommand implements Command
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Config $config,
        private readonly Database $db,
    ) {
    }

    public function name(): string
    {
        return 'db:reimport';
    }

    public function description(): string
    {
        return 'Drop all tables and rebuild from schema.sql + migrations (dev only)';
    }

    public function usage(): string
    {
        return '--force';
    }

    public function run(Input $input, Output $output): int
    {
        if (!$input->hasOption('force')) {
            $name = $this->config->get('DB_NAME', 'online_store');
            $output->error("This DROPS every table in \"{$name}\" and rebuilds it from schema.sql.");
            $output->error('Re-run with --force if that is what you want:');
            $output->error('  bin/console ' . $this->name() . ' --force');

            return 1;
        }

        $pdo = $this->db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', (string) $table) . '`');
            $output->line("dropped {$table}");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $database = $this->paths->root . '/database';
        $this->runSqlFile($pdo, $database . '/schema.sql');
        $output->line('applied schema.sql');

        $migrations = glob($database . '/migrations/*.sql') ?: [];
        sort($migrations);
        foreach ($migrations as $migration) {
            try {
                $this->runSqlFile($pdo, $migration);
                $output->line('applied ' . basename($migration));
            } catch (PDOException $e) {
                // schema.sql already contains what older migrations add.
                $output->line('skipped ' . basename($migration) . ' (' . $e->getMessage() . ')');
            }
        }

        $output->line('Database rebuilt.');

        return 0;
    }

    /** Runs a SQL file against the configured database, ignoring its CREATE DATABASE / USE lines. */
    private function runSqlFile(PDO $pdo, string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Could not read ' . $path);
        }
        $sql = preg_replace('/^\s*(CREATE\s+DATABASE|USE)\b[^;]*;/im', '', $sql) ?? $sql;
        if (trim($sql) !== '') {
            $pdo->exec($sql);
        }
    }
}
