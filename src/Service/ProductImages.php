<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Paths;

/**
 * Chooses which picture to show for products: stored photos when the files
 * exist, otherwise a generated placeholder.
 */
final class ProductImages
{
    public function __construct(
        private readonly Paths $paths,
        private readonly PlaceholderImage $placeholder,
    ) {
    }

    /**
     * Listing pages: sets each product's image_path, using only cached
     * placeholders so a big listing never generates images inline
     * (run `bin/console images:regenerate` to pre-generate them).
     *
     * @param list<array<string, mixed>> $products
     * @return list<array<string, mixed>>
     */
    public function forListing(array $products): array
    {
        foreach ($products as &$product) {
            $product['image_path'] = $this->existing((string) ($product['image_path'] ?? ''))
                ?? $this->placeholder->cachedPathFor($product);
        }

        return $products;
    }

    /**
     * Product page: every stored photo that still exists, else one placeholder.
     *
     * @param array<string, mixed> $product
     * @return list<string>
     */
    public function gallery(array $product): array
    {
        $decoded = json_decode((string) ($product['images'] ?? ''), true);
        $valid = array_values(array_filter(
            is_array($decoded) ? $decoded : [],
            fn ($path) => is_string($path) && $this->existing($path) !== null
        ));

        if ($valid !== []) {
            return $valid;
        }

        return [$this->existing((string) ($product['image_path'] ?? '')) ?? $this->placeholder->pathFor($product)];
    }

    private function existing(string $path): ?string
    {
        return $path !== '' && !str_contains($path, '..') && is_file($this->paths->public($path)) ? $path : null;
    }
}
