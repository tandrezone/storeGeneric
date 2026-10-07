<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Command;
use App\Console\Input;
use App\Console\Output;
use App\Service\DatabaseBackup;
use App\Support\Config;

/**
 * Restores a backup made by db:backup (.sql or .sql.gz) into the database
 * configured in .env, replacing every table the backup contains. Requires
 * --force. --with-uploads also unpacks the matching -uploads.zip (or the
 * zip given as --with-uploads=path) into public/assets/images/.
 */
final class RestoreDatabaseCommand implements Command
{
    public function __construct(
        private readonly DatabaseBackup $backup,
        private readonly Config $config,
    ) {
    }

    public function name(): string
    {
        return 'db:restore';
    }

    public function description(): string
    {
        return 'Restore a db:backup file into the configured database (needs --force)';
    }

    public function usage(): string
    {
        return '<file> --force [--with-uploads[=zip]]';
    }

    public function run(Input $input, Output $output): int
    {
        $file = $input->argument(0);
        if ($file === null) {
            $output->error('Usage: bin/console ' . $this->name() . ' ' . $this->usage());

            return 1;
        }
        if (!is_file($file)) {
            // Accept a bare file name from var/backups/.
            $candidate = $this->backup->defaultDir() . '/' . basename($file);
            if (basename($file) !== $file || !is_file($candidate)) {
                $output->error("Backup file not found: {$file}");

                return 1;
            }
            $file = $candidate;
        }

        $uploads = null;
        if ($input->hasOption('with-uploads')) {
            $uploads = $input->option('with-uploads') ?? dirname($file) . '/' . DatabaseBackup::uploadsName(basename($file));
            if (!is_file($uploads)) {
                $output->error("Uploads archive not found: {$uploads}");

                return 1;
            }
        }

        $database = $this->config->get('DB_NAME', 'online_store');
        if (!$input->hasOption('force')) {
            $output->error("This REPLACES every table in \"{$database}\" that is in {$file}" . ($uploads !== null ? ", and overwrites images from {$uploads}" : '') . '.');
            $output->error('Take a fresh backup first (bin/console db:backup), then re-run with --force:');
            $output->error('  bin/console ' . $this->name() . ' ' . $file . ' --force' . ($uploads !== null ? ' --with-uploads' : ''));

            return 1;
        }

        $started = microtime(true);
        $count = $this->backup->restore($file, static function (int $n) use ($output): void {
            $output->line("  {$n} statements…");
        });
        $output->line(sprintf('Restored %s into "%s": %d statement(s) in %.1fs.', basename($file), $database, $count, microtime(true) - $started));

        if ($uploads !== null) {
            $output->line(sprintf('Restored %d image(s) from %s.', $this->backup->restoreUploads($uploads), basename($uploads)));
        }

        return 0;
    }
}
