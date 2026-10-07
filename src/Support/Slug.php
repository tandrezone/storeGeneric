<?php

declare(strict_types=1);

namespace App\Support;

use Transliterator;

/** URL slugs: "Crème Brûlée 250g" → "creme-brulee-250g". */
final class Slug
{
    private const MAX_LENGTH = 80;

    public static function from(string $text, string $fallback = 'item'): string
    {
        $ascii = $text;
        if (class_exists(Transliterator::class)) {
            $ascii = Transliterator::create('Any-Latin; Latin-ASCII')?->transliterate($text) ?: $text;
        } elseif (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        }

        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
        $slug = rtrim(substr($slug, 0, self::MAX_LENGTH), '-');

        return $slug !== '' ? $slug : $fallback;
    }
}
