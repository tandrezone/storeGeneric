<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Paths;

/**
 * Generates (and caches under public/assets/images/generated) a neutral
 * placeholder picture showing the product's name, for products without a
 * photo. The file name includes a hash of the name, so renaming a product
 * produces a fresh placeholder.
 */
final class PlaceholderImage
{
    public const PUBLIC_DIR = 'assets/images/generated';
    private const WIDTH = 800;
    private const HEIGHT = 600;

    public function __construct(private readonly Paths $paths)
    {
    }

    /**
     * Path (relative to public/) for the product's placeholder, generating it if needed.
     *
     * @param array<string, mixed> $product
     */
    public function pathFor(array $product): string
    {
        $file = $this->fileFor($product);
        if (!is_file($file)) {
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0775, true);
            }
            $this->render($this->nameOf($product), $file);
        }

        return self::PUBLIC_DIR . '/' . basename($file);
    }

    /**
     * Cached placeholder path, or '' if it hasn't been generated yet (never generates).
     *
     * @param array<string, mixed> $product
     */
    public function cachedPathFor(array $product): string
    {
        $file = $this->fileFor($product);

        return is_file($file) ? self::PUBLIC_DIR . '/' . basename($file) : '';
    }

    /** @param array<string, mixed> $product */
    public function forget(array $product): void
    {
        $file = $this->fileFor($product);
        if (is_file($file)) {
            unlink($file);
        }
    }

    /** @param array<string, mixed> $product */
    private function nameOf(array $product): string
    {
        return trim((string) ($product['name'] ?? '')) ?: 'Product';
    }

    /** @param array<string, mixed> $product */
    private function fileFor(array $product): string
    {
        $name = $this->nameOf($product);

        return $this->paths->public(self::PUBLIC_DIR . '/' . (int) ($product['id'] ?? 0) . '-' . substr(md5('v2' . $name), 0, 8) . '.jpg');
    }

    private function render(string $name, string $destination): void
    {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imageantialias($image, true);

        // Soft vertical gradient background.
        for ($y = 0; $y < self::HEIGHT; $y++) {
            $shade = (int) (242 - 14 * ($y / self::HEIGHT));
            imageline($image, 0, $y, self::WIDTH, $y, imagecolorallocate($image, $shade, $shade + 1, $shade + 4));
        }
        // Simple package outline as a hint that this is a product shot.
        $line = imagecolorallocate($image, 196, 199, 206);
        imagesetthickness($image, 6);
        imagerectangle($image, 340, 120, 460, 230, $line);
        imageline($image, 340, 150, 460, 150, $line);

        $font = $this->paths->resources('fonts/Roboto-Regular.ttf');
        $text = imagecolorallocate($image, 52, 55, 62);
        $size = mb_strlen($name) > 30 ? 30 : 38;
        $lines = $this->wrap($name, $font, $size, 640);
        $lineHeight = (int) ($size * 1.45);
        $y = 330 - (int) ((count($lines) - 1) * $lineHeight / 2) + $size;
        foreach ($lines as $i => $lineText) {
            $box = imagettfbbox($size, 0, $font, $lineText);
            $x = (int) ((self::WIDTH - abs($box[2] - $box[0])) / 2);
            imagettftext($image, $size, 0, $x, $y + $i * $lineHeight, $text, $font, $lineText);
        }

        // Write to a temp file and rename into place: rename() is atomic, so
        // a concurrent request never serves a half-written file.
        $tmp = $destination . '.' . bin2hex(random_bytes(4)) . '.tmp';
        imagejpeg($image, $tmp, 88);
        rename($tmp, $destination);
    }

    /** @return list<string> at most 4 lines that fit $maxWidth */
    private function wrap(string $text, string $font, int $size, int $maxWidth): array
    {
        $lines = [];
        $current = '';
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            $box = imagettfbbox($size, 0, $font, $candidate);
            if (abs($box[2] - $box[0]) <= $maxWidth || $current === '') {
                $current = $candidate;
                continue;
            }
            $lines[] = $current;
            $current = $word;
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return array_slice($lines, 0, 4);
    }
}
