<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ProductRepository;
use App\Support\Paths;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Admin-driven counterpart to ImageDownloader: instead of pulling an image
 * from a remote URL, this saves a file the admin uploaded directly, and
 * lets them delete or re-order a product's existing images. Shares the
 * same public/assets/images/products storage and images/image_path
 * column conventions as the importer so both write paths stay compatible.
 */
final class ProductImageManager
{
    public function __construct(
        private readonly Paths $paths,
        private readonly ProductRepository $products,
        private readonly ImageUploader $uploader,
    ) {
    }

    private const PUBLIC_SUBDIR = 'assets/images/products';

    /**
     * Validates an uploaded image and, if it passes, saves it and
     * appends it to $productId's images (setting image_path too if this
     * is the first image). Returns the new relative path. Throws a
     * user-facing message on any validation failure — callers should show
     * that message rather than a generic "upload failed".
     */
    public function addUpload(int $productId, UploadedFileInterface $file): string
    {
        $relativePath = $this->uploader->store($file, self::PUBLIC_SUBDIR, (string) $productId);
        $paths = $this->products->imagePaths($productId);
        $paths[] = $relativePath;
        $this->products->saveImagePaths($productId, array_values(array_unique($paths)));

        return $relativePath;
    }

    /**
     * Removes $relativePath from $productId's images and deletes the file,
     * promoting the next remaining image to image_path if the removed one
     * was the main. No-ops if $relativePath isn't actually one of this
     * product's stored images — guards against deleting an unrelated file
     * via a crafted path, since we only ever unlink paths already present
     * in the DB record rather than trusting the input directly.
     */
    public function remove(int $productId, string $relativePath): void
    {
        $paths = $this->products->imagePaths($productId);

        if (!in_array($relativePath, $paths, true)) {
            return;
        }

        $remaining = array_values(array_filter($paths, static fn ($path) => $path !== $relativePath));
        $this->products->saveImagePaths($productId, $remaining);

        $fullPath = $this->localDir() . '/' . basename($relativePath);
        if (is_file($fullPath)) {
            unlink($fullPath);
        }
    }

    /**
     * Deletes a product together with its image files. Files are removed only
     * after the row is gone, so a product still referenced by orders (which
     * makes the DELETE fail) keeps its photos.
     *
     * @throws \PDOException when orders still reference the product's variants
     */
    public function deleteProduct(int $productId): void
    {
        $paths = $this->products->imagePaths($productId);
        $this->products->delete($productId);

        foreach ($paths as $path) {
            $fullPath = $this->localDir() . '/' . basename($path);
            if (is_file($fullPath)) {
                unlink($fullPath);
            }
        }
    }

    /**
     * Reorders $productId's images so $relativePath comes first (the main
     * photo shown on listings). No-ops if it isn't one of this product's
     * stored images, for the same reason as remove() above.
     */
    public function setMain(int $productId, string $relativePath): void
    {
        $paths = $this->products->imagePaths($productId);

        if (!in_array($relativePath, $paths, true)) {
            return;
        }

        $reordered = array_values(array_unique(array_merge([$relativePath], $paths)));
        $this->products->saveImagePaths($productId, $reordered);
    }

    /**
     * Attaches a file that's already on disk under the products directory
     * (e.g. saved by ImageDownloader) to $productId's image list, without
     * going through the $_FILES upload flow addUpload() expects. Used by
     * bin/console images:check once a downloaded web-search candidate has
     * been reviewed. No-ops if the file isn't actually present.
     */
    public function attachExisting(int $productId, string $relativePath, bool $asMain = true): void
    {
        if (!is_file($this->localDir() . '/' . basename($relativePath))) {
            return;
        }
        $paths = $this->products->imagePaths($productId);
        $merged = $asMain ? array_merge([$relativePath], $paths) : array_merge($paths, [$relativePath]);

        $this->products->saveImagePaths($productId, array_values(array_unique($merged)));
    }

    /**
     * Moves one image $offset places (-1 = earlier, +1 = later) in
     * $productId's list; the first image is the main one. No-ops for paths
     * that aren't this product's or moves past either end.
     */
    public function move(int $productId, string $relativePath, int $offset): void
    {
        $paths = $this->products->imagePaths($productId);
        $from = array_search($relativePath, $paths, true);
        if ($from === false) {
            return;
        }
        $to = $from + $offset;
        if ($to < 0 || $to >= count($paths) || $to === $from) {
            return;
        }

        array_splice($paths, $from, 1);
        array_splice($paths, $to, 0, [$relativePath]);
        $this->products->saveImagePaths($productId, $paths);
    }

    /**
     * Saves a new order for $productId's images (e.g. after drag and drop).
     * Only the product's own paths are kept; any it has that are missing from
     * $order stay at the end, so a stale page can't drop images.
     *
     * @param list<string> $order
     */
    public function reorder(int $productId, array $order): void
    {
        $paths = $this->products->imagePaths($productId);
        $ordered = array_values(array_unique(array_filter($order, static fn (string $p) => in_array($p, $paths, true))));
        $reordered = array_values(array_unique(array_merge($ordered, $paths)));
        if ($reordered !== $paths) {
            $this->products->saveImagePaths($productId, $reordered);
        }
    }

    private function localDir(): string
    {
        return $this->paths->public(self::PUBLIC_SUBDIR);
    }
}
