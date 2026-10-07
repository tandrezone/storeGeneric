<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Command;
use App\Console\Input;
use App\Console\Output;
use App\Service\DatabaseBackup;
use App\Support\Config;

/**
 * Dumps the database (schema + data, through PDO — no mysqldump needed) to
 * var/backups/backup-<db>-<YYYYmmdd-HHiiss>.sql[.gz]. Cron example (daily,
 * keep two weeks):
 *   30 3 * * * cd /var/www/store && bin/console db:backup --gzip --keep=14
 */
final class BackupDatabaseCommand implements Command
{
    public function __construct(
        private readonly DatabaseBackup $backup,
        private readonly Config $config,
    ) {
    }

    public function name(): string
    {
        return 'db:backup';
    }

    public function description(): string
    {
        return 'Back up the database (and optionally uploaded images) to var/backups/';
    }

    public function usage(): string
    {
        return '[--output=path] [--gzip] [--with-uploads] [--keep=N]';
    }

    public function run(Input $input, Output $output): int
    {
        $gzip = $input->hasOption('gzip');
        $keep = $input->option('keep');
        if ($keep !== null && (!ctype_digit($keep) || (int) $keep < 1)) {
            $output->error('--keep must be a number of 1 or more.');

            return 1;
        }

        // --output: a directory (file name generated) or a full file path.
        $target = $input->option('output');
        $name = $this->backup->fileName($this->config->get('DB_NAME', 'online_store'), $gzip);
        if ($target === null || $target === '') {
            $file = $this->backup->defaultDir() . '/' . $name;
        } elseif (is_dir($target) || str_ends_with($target, '/') || str_ends_with($target, '\\')) {
            $file = rtrim($target, '/\\') . '/' . $name;
        } else {
            $file = $target;
        }
        if (is_file($file)) {
            $output->error("{$file} already exists — choose another --output.");

            return 1;
        }

        $started = microtime(true);
        $stats = $this->backup->dump($file, $gzip);
        $output->line(sprintf(
            'Backed up %d table(s), %d row(s) to %s (%s) in %.1fs.',
            $stats['tables'],
            $stats['rows'],
            $file,
            $this->size($stats['bytes']),
            microtime(true) - $started
        ));

        if ($input->hasOption('with-uploads')) {
            $zip = dirname($file) . '/' . DatabaseBackup::uploadsName(basename($file));
            $count = $this->backup->zipUploads($zip);
            $output->line(sprintf('Saved %d uploaded image(s) to %s (%s).', $count, $zip, $this->size((int) filesize($zip))));
        }

        if ($keep !== null) {
            foreach ($this->backup->prune(dirname($file), (int) $keep) as $deleted) {
                $output->line("Deleted old backup {$deleted}");
            }
        }

        return 0;
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
