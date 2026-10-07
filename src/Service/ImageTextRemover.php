<?php

declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\GeminiClient;
use App\Support\Paths;
use RuntimeException;

/**
 * Edits a product photo in place via Gemini's image model, removing any
 * text, watermarks, price stickers and logos baked into the shot.
 */
final class ImageTextRemover
{
    public function __construct(
        private readonly Paths $paths,
        private readonly GeminiClient $gemini,
    ) {
    }

    private const PROMPT = <<<PROMPT
        Remove all text, watermarks, price stickers, labels, and logos
        from this product photo — including any logo or brand mark
        printed directly on the product or its packaging, not just ones
        overlaid on top of the photo. Keep the product's shape, packaging
        design, colors, proportions, and background otherwise unchanged,
        cleanly filling in whatever was underneath each removed element.
        Return the edited image.
        PROMPT;

    /**
     * Sends the image at $relativePath (relative to /public) to Gemini and
     * overwrites it with the edited result. No-ops if the file is missing;
     * throws on any other failure.
     */
    public function removeTextFromImage(string $relativePath): void
    {
        $fullPath = $this->paths->public($relativePath);
        if (!is_file($fullPath)) {
            return;
        }

        $data = file_get_contents($fullPath);
        if ($data === false) {
            throw new RuntimeException("Could not read {$relativePath}.");
        }

        $info = @getimagesizefromstring($data);
        if ($info === false) {
            throw new RuntimeException("{$relativePath} is not a readable image.");
        }
        $mime = $info['mime'];

        $edited = $this->gemini->editImage($data, $mime, self::PROMPT);
        if (@getimagesizefromstring($edited) === false) {
            throw new RuntimeException("Gemini's reply for {$relativePath} was not a valid image.");
        }

        // Write to a temp file and rename into place — rename() is atomic,
        // so a concurrent request never sees a half-written file.
        $tmpPath = $fullPath . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmpPath, $edited);
        rename($tmpPath, $fullPath);
    }
}
