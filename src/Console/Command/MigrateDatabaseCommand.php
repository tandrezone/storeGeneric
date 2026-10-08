<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Command;
use App\Console\Input;
use App\Console\Output;
use App\Service\DatabaseMigrator;
use RuntimeException;

/**
 * Runs the database migrations that haven't run yet (safe for production,
 * unlike db:reimport). Each file in database/migrations/ is applied once, in
 * order, and recorded in the schema_migrations table.
 */
final class MigrateDatabaseCommand implements Command
{
    public function __construct(private readonly DatabaseMigrator $migrator)
    {
    }

    public function name(): string
    {
        return 'db:migrate';
    }

    public function description(): string
    {
        return 'Apply the database migrations that have not run yet';
    }

    public function usage(): string
    {
        return '[--status] [--dry-run] [--baseline=NNN]';
    }

    public function run(Input $input, Output $output): int
    {
        if ($input->hasOption('status')) {
            return $this->status($output);
        }

        $baseline = $input->option('baseline');
        if ($input->hasOption('baseline') && ($baseline === null || !ctype_digit($baseline))) {
            $output->error('--baseline needs a migration number, e.g. --baseline=012');

            return 1;
        }
        $dryRun = $input->hasOption('dry-run');

        try {
            $result = $this->migrator->migrate(
                static function (string $message) use ($output): void {
                    $output->line($message);
                },
                $baseline !== null ? (int) $baseline : null,
                $dryRun,
            );
        } catch (RuntimeException $e) {
            $output->error($e->getMessage());

            return 1;
        }

        $count = count($result['applied']);
        if ($dryRun) {
            $output->line($count === 0 && !$result['loaded_schema'] ? 'Nothing to apply (dry run).' : 'Dry run: nothing was changed.');
        } elseif ($result['loaded_schema']) {
            $output->line('Done: database created from schema.sql.');
        } else {
            $output->line($count === 0 ? 'Database is up to date.' : "Done: {$count} migration(s) applied.");
        }

        return 0;
    }

    private function status(Output $output): int
    {
        $files = $this->migrator->files();
        $applied = $this->migrator->applied();

        if ($applied === []) {
            $output->line($this->migrator->isEmpty()
                ? 'Empty database: db:migrate will load schema.sql.'
                : 'No migration history yet (database was set up by hand): db:migrate will record the already-applied ones first.');
        }
        foreach ($files as $file) {
            $output->line((in_array($file, $applied, true) ? '[x] ' : '[ ] ') . $file);
        }
        $pending = $this->migrator->pending();
        $output->line(sprintf('%d of %d migration(s) recorded as applied.', count($files) - count($pending), count($files)));

        return 0;
    }
}
