<?php

declare(strict_types=1);

namespace App\Service;

use App\I18n\Translator;
use App\Support\Paths;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Saves an uploaded image under public/<dir>/ after checking the real file
 * (never the client's claimed type). Shared by product and category images.
 */
final class ImageUploader
{
    public const MAX_BYTES = 8 * 1024 * 1024;
    public const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    public function __construct(
        private readonly Paths $paths,
        private readonly Translator $translator,
    ) {
    }

    /**
     * Validates and stores the upload as public/$publicDir/$prefix-<random>.<ext>.
     * Returns the path relative to /public. Throws a user-facing message on
     * any validation failure.
     */
    public function store(UploadedFileInterface $file, string $publicDir, string $prefix, int $maxBytes = self::MAX_BYTES): string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadErrorMessage($file->getError()));
        }
        if ((int) $file->getSize() > $maxBytes) {
            throw new RuntimeException($this->translator->trans('Image is larger than {size}MB.', ['size' => round($maxBytes / 1024 / 1024, 1)]));
        }

        $dir = $this->paths->public($publicDir);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException($this->translator->trans('Could not create {dir} — check folder permissions.', ['dir' => 'public/' . $publicDir]));
        }

        // Move first, then inspect the real file — never trust the client's type.
        $tmp = $dir . '/.upload-' . bin2hex(random_bytes(6));
        $file->moveTo($tmp);
        $info = @getimagesize($tmp);
        $ext = is_array($info) ? (self::ALLOWED_MIME[$info['mime']] ?? null) : null;
        if ($ext === null) {
            @unlink($tmp);
            throw new RuntimeException($this->translator->trans('Only JPEG, PNG, WebP, and GIF images are allowed.'));
        }

        $filename = $prefix . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        rename($tmp, $dir . '/' . $filename);

        return $publicDir . '/' . $filename;
    }

    /** Deletes a file previously stored in $publicDir (ignores anything outside it). */
    public function delete(string $relativePath, string $publicDir): void
    {
        if (!str_starts_with($relativePath, $publicDir . '/') || str_contains($relativePath, '..')) {
            return;
        }
        $full = $this->paths->public($publicDir) . '/' . basename($relativePath);
        if (is_file($full)) {
            unlink($full);
        }
    }

    private function uploadErrorMessage(int $code): string
    {
        return $this->translator->trans(match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That image is too large.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            default => 'Upload failed.',
        });
    }
}
