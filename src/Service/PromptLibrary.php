<?php

declare(strict_types=1);

namespace App\Service;

use App\I18n\Translator;
use App\Support\Paths;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * AI prompts uploaded in Admin → Settings: plain-text .md / .txt files kept
 * in var/prompts (outside public/), listed, downloaded and deleted by name.
 */
final class PromptLibrary
{
    public const DIR = 'prompts';
    public const EXTENSIONS = ['md', 'txt'];
    public const MAX_BYTES = 512 * 1024;

    /** Reserved base names (.md or .txt) that replace a built-in prompt: name => placeholders. */
    public const MAGIC_EDIT = 'magic-edit';
    public const TRANSLATE_PRODUCT = 'translate-product';
    public const MAGIC_PAGE = 'magic-page';
    public const MAGIC_NEWSLETTER = 'magic-newsletter';
    public const RESERVED = [
        self::MAGIC_EDIT => ['{instruction}', '{name}', '{short_description}', '{long_description}', '{field_json}'],
        self::TRANSLATE_PRODUCT => ['{language}', '{locale}', '{from}', '{from_locale}', '{name}', '{short_description}', '{long_description}', '{field_json}'],
        self::MAGIC_PAGE => ['{page}', '{slug}', '{language}', '{store}', '{catalog}', '{instruction}', '{title}', '{body}'],
        self::MAGIC_NEWSLETTER => ['{language}', '{store}', '{catalog}', '{instruction}', '{subject}', '{message}'],
    ];

    public function __construct(
        private readonly Paths $paths,
        private readonly Translator $translator,
    ) {
    }

    /** @return list<array{name: string, size: int, modified: int}> newest first */
    public function all(): array
    {
        $prompts = [];
        foreach (glob($this->paths->var(self::DIR) . '/*') ?: [] as $file) {
            $name = basename($file);
            if (is_file($file) && self::validName($name)) {
                $prompts[] = ['name' => $name, 'size' => (int) filesize($file), 'modified' => (int) filemtime($file)];
            }
        }
        usort($prompts, static fn (array $a, array $b) => $b['modified'] <=> $a['modified'] ?: strcmp($a['name'], $b['name']));

        return $prompts;
    }

    /** Absolute path of a stored prompt, or null if there is none by that name. */
    public function path(string $name): ?string
    {
        if (!self::validName($name)) {
            return null;
        }
        $file = $this->paths->var(self::DIR . '/' . $name);

        return is_file($file) ? $file : null;
    }

    public function contents(string $name): ?string
    {
        $file = $this->path($name);

        return $file === null ? null : (string) file_get_contents($file);
    }

    /**
     * The uploaded override for a built-in prompt ("magic-edit" or
     * "translate-product", as .md or .txt) with its {placeholders} filled in
     * from $vars, or null when there is none (or it is blank) so the caller
     * uses its hard-coded prompt. Unknown placeholders are left as typed.
     *
     * @param array<string, string> $vars placeholder name (without braces) => value
     */
    public function override(string $reserved, array $vars): ?string
    {
        if (!isset(self::RESERVED[$reserved])) {
            return null;
        }
        foreach (self::EXTENSIONS as $ext) {
            $text = $this->contents($reserved . '.' . $ext);
            if ($text !== null && trim($text) !== '') {
                return self::fill($text, $vars);
            }
        }

        return null;
    }

    /** Replaces {key} with its value in one pass (values are never re-scanned for placeholders). */
    public static function fill(string $template, array $vars): string
    {
        $map = [];
        foreach ($vars as $key => $value) {
            $map['{' . $key . '}'] = (string) $value;
        }

        return strtr($template, $map);
    }

    /**
     * Stores an uploaded prompt (replacing one with the same name) and
     * returns its file name.
     */
    public function store(UploadedFileInterface $upload): string
    {
        $name = self::cleanName((string) $upload->getClientFilename());
        if ($upload->getError() !== UPLOAD_ERR_OK || (int) $upload->getSize() > self::MAX_BYTES || $name === null) {
            throw new RuntimeException($this->translator->trans('Upload failed — choose a .md or .txt file (max {kb} KB).', ['kb' => self::MAX_BYTES >> 10]));
        }

        $text = (string) $upload->getStream();
        if (str_contains($text, "\0") || !mb_check_encoding($text, 'UTF-8')) {
            throw new RuntimeException($this->translator->trans('"{name}" is not a UTF-8 text file.', ['name' => $name]));
        }

        $dir = $this->paths->var(self::DIR);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new RuntimeException($this->translator->trans('Could not create var/{dir} — check folder permissions.', ['dir' => self::DIR]));
        }
        if (file_put_contents($dir . '/' . $name, $text, LOCK_EX) === false) {
            throw new RuntimeException($this->translator->trans('Could not save "{name}".', ['name' => $name]));
        }

        return $name;
    }

    public function delete(string $name): void
    {
        $file = $this->path($name);
        if ($file === null) {
            throw new RuntimeException($this->translator->trans('That prompt does not exist.'));
        }
        @unlink($file);
    }

    /** A safe file name from the uploaded one ("My prompt!.MD" → "My-prompt.md"), or null if the type isn't allowed. */
    public static function cleanName(string $original): ?string
    {
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXTENSIONS, true)) {
            return null;
        }
        // Lower case, so "Magic-Edit.md" still replaces the reserved "magic-edit" prompt.
        $base = strtolower((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', pathinfo(basename($original), PATHINFO_FILENAME)));
        $base = substr(trim($base, '-'), 0, 80);

        return ($base !== '' ? $base : 'prompt') . '.' . $ext;
    }

    private static function validName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_][A-Za-z0-9_-]{0,79}\.(md|txt)$/', $name) === 1;
    }
}
