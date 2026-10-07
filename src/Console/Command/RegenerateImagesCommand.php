<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Command;
use App\Console\Input;
use App\Console\Output;
use App\Repository\ProductRepository;
use App\Service\PlaceholderImage;
use App\Support\Paths;

/**
 * Makes sure every product without a real photo has a generated placeholder.
 * --force redraws placeholders that are already cached.
 */
final class RegenerateImagesCommand implements Command
{
    public function __construct(
        private readonly Paths $paths,
        private readonly ProductRepository $products,
        private readonly PlaceholderImage $placeholders,
    ) {
    }

    public function name(): string
    {
        return 'images:regenerate';
    }

    public function description(): string
    {
        return 'Generate placeholder images for products without a photo';
    }

    public function usage(): string
    {
        return '[--force]';
    }

    public function run(Input $input, Output $output): int
    {
        $force = $input->hasOption('force');
        $generated = 0;
        $skipped = 0;

        foreach ($this->products->findAllForImages() as $product) {
            $imagePath = (string) ($product['image_path'] ?? '');
            if ($imagePath !== '' && is_file($this->paths->public($imagePath))) {
                $skipped++;
                continue;
            }
            if ($force) {
                $this->placeholders->forget($product);
            }
            $this->placeholders->pathFor($product);
            $generated++;
            $output->line("generated #{$product['id']} — {$product['name']}");
        }

        $output->line(sprintf('done: %d generated, %d skipped (already have a real photo)', $generated, $skipped));

        return 0;
    }
}
