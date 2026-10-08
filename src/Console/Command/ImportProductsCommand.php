<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Command;
use App\Console\Input;
use App\Console\Output;
use App\Service\ProductJsonImporter;
use RuntimeException;

/**
 * Imports a JSON file written by `products:export` (or Admin → Products →
 * Export JSON). Images that aren't stored locally are downloaded from their
 * URL (or decoded when the file embeds them) and saved under
 * public/assets/images/products/. Unlike the admin page it isn't bound by a
 * request time limit, so it suits big catalogs.
 */
final class ImportProductsCommand implements Command
{
    public function __construct(private readonly ProductJsonImporter $importer)
    {
    }

    public function name(): string
    {
        return 'products:import';
    }

    public function description(): string
    {
        return 'Import products from a JSON export, downloading images that are not stored locally';
    }

    public function usage(): string
    {
        return '<file> [--dry-run] [--create-categories] [--no-update] [--no-images]';
    }

    public function run(Input $input, Output $output): int
    {
        $file = $input->argument(0);
        if ($file === null || !is_file($file)) {
            $output->error('Usage: bin/console products:import ' . $this->usage());
            $output->error($file === null ? 'Give the JSON file to import.' : "File not found: {$file}");

            return 1;
        }
        if (filesize($file) > ProductJsonImporter::MAX_BYTES) {
            $output->error('The file is larger than ' . (ProductJsonImporter::MAX_BYTES >> 20) . ' MB.');

            return 1;
        }

        $apply = !$input->hasOption('dry-run');
        $options = [
            'create_categories' => $input->hasOption('create-categories'),
            'update_existing'   => !$input->hasOption('no-update'),
            'images'            => !$input->hasOption('no-images'),
        ];

        try {
            $products = $this->importer->parse((string) file_get_contents($file));
            $report = $this->importer->run($products, $options, $apply, static function (int $number, int $total, array $row) use ($output): void {
                $line = sprintf('[%d/%d] %-9s %s', $number, $total, $row['action'], $row['name'] !== '' ? $row['name'] : '(no name)');
                $images = $row['images'];
                if ($images['download'] + $images['embedded'] > 0) {
                    $line .= sprintf('  images: %d to fetch', $images['download'] + $images['embedded']);
                }
                $output->line($line);
                foreach ($row['errors'] as $error) {
                    $output->line('    error: ' . $error);
                }
                foreach ($row['warnings'] as $warning) {
                    $output->line('    warning: ' . $warning);
                }
            });
        } catch (RuntimeException $e) {
            $output->error($e->getMessage());

            return 1;
        }

        $c = $report['counts'];
        $output->line('');
        $output->line(($apply ? 'Imported' : 'Dry run — would import') . sprintf(
            ': %d created, %d updated, %d unchanged, %d skipped, %d with errors; images: %d kept, %d %s, %d embedded, %d failed.',
            $c['create'],
            $c['update'],
            $c['unchanged'],
            $c['skip'],
            $c['error'],
            $c['images_local'],
            $c['images_download'],
            $apply ? 'downloaded' : 'to download',
            $c['images_embedded'],
            $c['images_failed']
        ));

        return $c['error'] > 0 ? 1 : 0;
    }
}
