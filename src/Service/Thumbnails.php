<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Paths;

/**
 * Resized copies of product images for the storefront (srcset), cached under
 * public/assets/images/thumbs/<width>/<products|generated>/<file>.<webp|jpg>.
 *
 * Thumbnails are made on demand: the first request for a missing file
 * reaches ThumbnailController, which calls generate(); after that the web
 * server serves the file directly. Images are never upscaled, and only files
 * inside the product and placeholder image folders are ever read.
 */
final class Thumbnails
{
    public const PUBLIC_DIR = 'assets/images/thumbs';
    public const WIDTHS = [400, 800];
    /** Folders (relative to public/assets/images) whose images can be resized. */
    private const SOURCE_DIRS = ['products', 'generated'];
    private const QUALITY = 82;

    /** @var array<string, array{width: int, height: int}|null> */
    private array $sizes = [];

    public function __construct(private readonly Paths $paths)
    {
    }

    /** URL of $path resized to $width, or the original's URL when no thumbnail applies. */
    public function url(string $path, int $width): string
    {
        $path = ltrim($path, '/');
        $sub = $this->sourceSubPath($path);
        $size = $this->size($path);
        if ($sub === null || $size === null || !in_array($width, self::WIDTHS, true) || $width >= $size['width']) {
            return '/' . $path;
        }

        $relative = self::PUBLIC_DIR . '/' . $width . '/' . $sub . '.' . $this->format();
        $file = $this->paths->public($relative);
        $sourceTime = (int) filemtime($this->paths->public($path));
        if (is_file($file) && filemtime($file) < $sourceTime) {
            @unlink($file); // the original was replaced: make it again on the next request
        }

        return '/' . $relative . '?v=' . $sourceTime;
    }

    /** "srcset" value: every smaller thumbnail plus the original at its own width. */
    public function srcset(string $path): string
    {
        $path = ltrim($path, '/');
        $size = $this->size($path);
        if ($size === null || $this->sourceSubPath($path) === null) {
            return '';
        }

        $candidates = [];
        foreach (self::WIDTHS as $width) {
            if ($width < $size['width']) {
                $candidates[] = $this->url($path, $width) . ' ' . $width . 'w';
            }
        }
        $candidates[] = '/' . $path . ' ' . $size['width'] . 'w';

        return implode(', ', $candidates);
    }

    /** @return array{width: int, height: int}|null pixel size of a public image */
    public function size(string $path): ?array
    {
        $path = ltrim($path, '/');
        if (!array_key_exists($path, $this->sizes)) {
            $file = $this->paths->public($path);
            $info = $path !== '' && !str_contains($path, '..') && is_file($file) ? @getimagesize($file) : false;
            $this->sizes[$path] = $info !== false && $info[0] > 0 && $info[1] > 0
                ? ['width' => $info[0], 'height' => $info[1]]
                : null;
        }

        return $this->sizes[$path];
    }

    /**
     * Creates the thumbnail for "<products|generated>/<file>.<webp|jpg>" at
     * $width and returns its absolute path, or null if the request is invalid.
     */
    public function generate(int $width, string $thumbPath): ?string
    {
        if (!in_array($width, self::WIDTHS, true) || str_contains($thumbPath, '..') || str_contains($thumbPath, "\0")) {
            return null;
        }
        if (!preg_match('~^((?:' . implode('|', self::SOURCE_DIRS) . ')/[A-Za-z0-9._/-]+\.(?:jpe?g|png|gif|webp))\.(webp|jpg)$~i', $thumbPath, $m)) {
            return null;
        }
        [, $sourceSub, $format] = $m;
        $format = strtolower($format);
        if ($format === 'webp' && !function_exists('imagewebp')) {
            return null;
        }

        $source = $this->resolveSource($sourceSub);
        $info = $source !== null ? @getimagesize($source) : false;
        if ($info === false || $width >= $info[0]) {
            return null; // missing, not an image, or would be an upscale
        }

        $target = $this->paths->public(self::PUBLIC_DIR . '/' . $width . '/' . $thumbPath);
        if (is_file($target) && filemtime($target) >= filemtime((string) $source)) {
            return $target;
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg((string) $source),
            IMAGETYPE_PNG  => @imagecreatefrompng((string) $source),
            IMAGETYPE_GIF  => @imagecreatefromgif((string) $source),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp((string) $source) : false,
            default        => false,
        };
        if ($image === false) {
            return null;
        }

        $height = max(1, (int) round($info[1] * $width / $info[0]));
        $resized = imagecreatetruecolor($width, $height);
        if ($format === 'jpg') {
            imagefill($resized, 0, 0, (int) imagecolorallocate($resized, 255, 255, 255)); // flatten transparency
        } else {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
        unset($image);

        if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
            unset($resized);

            return null;
        }
        // Write then rename: concurrent requests never see a half-written file.
        $tmp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $ok = $format === 'webp' ? imagewebp($resized, $tmp, self::QUALITY) : imagejpeg($resized, $tmp, self::QUALITY);
        unset($resized);
        if (!$ok || !rename($tmp, $target)) {
            @unlink($tmp);

            return null;
        }

        return $target;
    }

    /** Output format for new thumbnails: WebP when GD supports it, else JPEG. */
    public function format(): string
    {
        return function_exists('imagewebp') ? 'webp' : 'jpg';
    }

    /** "assets/images/products/x.jpg" → "products/x.jpg"; null for anything outside the source folders. */
    private function sourceSubPath(string $path): ?string
    {
        foreach (self::SOURCE_DIRS as $dir) {
            $prefix = 'assets/images/' . $dir . '/';
            if (str_starts_with($path, $prefix) && !str_contains($path, '..')) {
                return substr($path, strlen('assets/images/'));
            }
        }

        return null;
    }

    /** Absolute path of a source image, only if it really is inside one of the source folders. */
    private function resolveSource(string $sourceSub): ?string
    {
        $real = realpath($this->paths->public('assets/images/' . $sourceSub));
        if ($real === false || !is_file($real)) {
            return null;
        }
        foreach (self::SOURCE_DIRS as $dir) {
            $base = realpath($this->paths->public('assets/images/' . $dir));
            if ($base !== false && str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }

        return null;
    }
}
