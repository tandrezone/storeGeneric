<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Command;
use App\Console\Input;
use App\Console\Output;
use App\Infrastructure\GeminiClient;
use App\Repository\ProductRepository;
use App\Service\ImageDownloader;
use App\Service\ImageTextRemover;
use App\Service\ProductImageManager;
use App\Support\Paths;
use Throwable;

/**
 * Cleans up a product's main photo: removes branding with Gemini, or falls back
 * to a web image search. The search result is only downloaded for review
 * unless --apply is passed.
 */
final class CheckImageCommand implements Command
{
    public function __construct(
        private readonly Paths $paths,
        private readonly ProductRepository $products,
        private readonly ImageTextRemover $textRemover,
        private readonly GeminiClient $gemini,
        private readonly ImageDownloader $downloader,
        private readonly ProductImageManager $images,
    ) {
    }

    public function name(): string
    {
        return 'images:check';
    }

    public function description(): string
    {
        return "Remove branding from a product's photo, or find a replacement on the web";
    }

    public function usage(): string
    {
        return '<product_id> [--apply]';
    }

    public function run(Input $input, Output $output): int
    {
        $id = $input->argument(0);
        if ($id === null || !ctype_digit($id)) {
            $output->error('Usage: bin/console ' . $this->name() . ' ' . $this->usage());

            return 1;
        }
        $productId = (int) $id;

        $product = $this->products->findForAdmin($productId);
        if ($product === null) {
            $output->error("No product with id {$productId}.");

            return 1;
        }

        $imagePath = trim((string) ($product['image_path'] ?? ''));
        if ($imagePath === '') {
            $output->error("Product #{$productId} ({$product['name']}) has no image.");

            return 1;
        }

        $output->line("Product #{$productId} — {$product['name']}");
        $output->line("Current image: {$imagePath}");
        $output->line();

        if (!is_file($this->paths->public($imagePath))) {
            $output->line("Local image file not found on disk ({$imagePath}) — nothing to de-brand.");
        } else {
            $output->line('Removing branding via Gemini...');
            try {
                $this->textRemover->removeTextFromImage($imagePath);
                $output->line("Done — branding removed, {$imagePath} updated in place.");

                return 0;
            } catch (Throwable $e) {
                $output->line("Branding removal failed: {$e->getMessage()}");
            }
        }

        $output->line();
        $output->line('Falling back to a web image search...');
        $url = $this->gemini->findImageUrl((string) $product['name']);
        if ($url === null) {
            $output->error('Web search did not turn up a usable image URL.');

            return 1;
        }
        $output->line("Candidate image found: {$url}");

        $downloaded = $this->downloader->download($url, $productId);
        if ($downloaded === null) {
            $output->error('Could not download the candidate image (unreachable or not a real image).');

            return 1;
        }

        if ($input->hasOption('apply')) {
            $this->images->attachExisting($productId, $downloaded);
            $output->line("Saved and applied as the product's main image: public/{$downloaded}");
        } else {
            $output->line("Saved for review (NOT applied to the product): public/{$downloaded}");
            $output->line("If it's the right product, re-run with --apply:");
            $output->line("  bin/console {$this->name()} {$productId} --apply");
        }

        return 0;
    }
}
