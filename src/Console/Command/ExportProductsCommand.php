<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Command;
use App\Console\Input;
use App\Console\Output;
use App\Repository\ProductRepository;
use App\Service\ProductJsonExporter;
use App\Support\Paths;

/**
 * Writes every product (variants, translations, image links) to a JSON file
 * that `products:import` — here or on another shop — can read.
 */
final class ExportProductsCommand implements Command
{
    public function __construct(
        private readonly ProductJsonExporter $exporter,
        private readonly Paths $paths,
    ) {
    }

    public function name(): string
    {
        return 'products:export';
    }

    public function description(): string
    {
        return 'Export products with variants, translations and images to a JSON file';
    }

    public function usage(): string
    {
        return '[--output=path] [--status=approved] [--embed-images]';
    }

    public function run(Input $input, Output $output): int
    {
        $status = $input->option('status');
        if ($status !== null && !in_array($status, ProductRepository::STATUSES, true)) {
            $output->error('--status must be one of: ' . implode(', ', ProductRepository::STATUSES));

            return 1;
        }

        $file = $input->option('output') ?? $this->paths->var('exports') . '/products-' . date('Ymd-His') . '.json';
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $output->error("Could not create {$dir}.");

            return 1;
        }
        $handle = @fopen($file, 'wb');
        if ($handle === false) {
            $output->error("Could not write {$file}.");

            return 1;
        }

        $count = $this->exporter->write($handle, $status, null, $input->hasOption('embed-images'));
        fclose($handle);

        $output->line(sprintf('Exported %d product(s) to %s (%s).', $count, $file, $this->size((int) filesize($file))));

        return 0;
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
