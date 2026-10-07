<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Command;
use App\Console\Input;
use App\Console\Output;
use App\Repository\ProductRepository;
use App\Service\ImageDownloader;
use App\Service\ProductImageManager;

/**
 * Downloads an image (SSRF-guarded, mime-checked) and attaches it to a product,
 * as the main image by default or appended to the gallery with --gallery.
 */
final class AddImageCommand implements Command
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ImageDownloader $downloader,
        private readonly ProductImageManager $images,
    ) {
    }

    public function name(): string
    {
        return 'images:add';
    }

    public function description(): string
    {
        return 'Download an image from a URL and attach it to a product';
    }

    public function usage(): string
    {
        return '<product_id> <image_url> [--gallery]';
    }

    public function run(Input $input, Output $output): int
    {
        $id = $input->argument(0);
        $url = trim((string) $input->argument(1));
        if ($id === null || !ctype_digit($id) || $url === '') {
            $output->error('Usage: bin/console ' . $this->name() . ' ' . $this->usage());

            return 1;
        }

        $product = $this->products->findForAdmin((int) $id);
        if ($product === null) {
            $output->error("No product with id {$id}.");

            return 1;
        }

        $output->line("Product #{$id} — {$product['name']}");
        $output->line("Downloading {$url}...");
        $path = $this->downloader->download($url, (int) $id);
        if ($path === null) {
            $output->error('Could not download that URL (unreachable, blocked, or not a real image).');

            return 1;
        }

        $asMain = !$input->hasOption('gallery');
        $this->images->attachExisting((int) $id, $path, $asMain);
        $output->line("Saved: public/{$path}");
        $output->line($asMain ? "Set as the product's main image." : "Added to the product's gallery.");

        return 0;
    }
}
