<?php

declare(strict_types=1);

namespace App\Service;

use App\I18n\Translator;
use App\Infrastructure\GeminiClient;
use App\Repository\ProductTranslationRepository;
use App\Security\HtmlSanitizer;
use RuntimeException;

/**
 * Product name / short description / description in other languages.
 *
 * The text in `products` is written in the store's own language
 * (Admin → Settings → Store language). Every other language the store has
 * a catalog for (translations/<code>.php) can get its own text; empty
 * fields fall back to the original. The storefront shows the visitor's
 * language when it differs from the store's own.
 */
final class ProductTranslations
{
    public const MAX_NAME = 180;
    public const MAX_SHORT = 280;
    public const MAX_LONG_BYTES = 65535;

    public function __construct(
        private readonly Translator $translator,
        private readonly StoreSettings $store,
        private readonly ProductTranslationRepository $translations,
        private readonly HtmlSanitizer $sanitizer,
        private readonly GeminiClient $gemini,
    ) {
    }

    /** Locale the product text is written in (the store's language). */
    public function baseLocale(): string
    {
        return $this->translator->normalize($this->store->language()) ?? Translator::DEFAULT_LOCALE;
    }

    /** @return array<string, string> locale => language name, for every language product text can be translated into */
    public function locales(): array
    {
        $base = $this->baseLocale();

        return array_filter(
            $this->translator->languages(),
            static fn (string $name, string $code): bool => $code !== $base,
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * Locale to look translations up in for the current visitor: their
     * language, or '' when they browse in the store's own language.
     */
    public function storefrontLocale(): string
    {
        $locale = $this->translator->locale();

        return $locale === $this->baseLocale() ? '' : $locale;
    }

    public function isTranslatable(string $locale): bool
    {
        return isset($this->locales()[$locale]);
    }

    /**
     * Validates and saves one language of a product. Empty fields are fine
     * (they fall back to the original text).
     *
     * @param array<string, mixed> $input name, short_description, long_description
     * @return list<string> error messages (nothing is saved when there are any)
     */
    public function save(int $productId, string $locale, array $input): array
    {
        if (!$this->isTranslatable($locale)) {
            return [$this->translator->trans('That language is not available for translations.')];
        }

        $fields = [
            'name'              => trim((string) ($input['name'] ?? '')),
            'short_description' => trim((string) ($input['short_description'] ?? '')),
            'long_description'  => $this->cleanDescription((string) ($input['long_description'] ?? '')),
        ];

        $errors = [];
        if (mb_strlen($fields['name']) > self::MAX_NAME) {
            $errors[] = $this->translator->trans('The name can be at most {max} characters.', ['max' => self::MAX_NAME]);
        }
        if (mb_strlen($fields['short_description']) > self::MAX_SHORT) {
            $errors[] = $this->translator->trans('The short description can be at most {max} characters.', ['max' => self::MAX_SHORT]);
        }
        if (strlen($fields['long_description']) > self::MAX_LONG_BYTES) {
            $errors[] = $this->translator->trans('The long description is too long.');
        }
        if ($errors !== []) {
            return $errors;
        }

        $this->translations->save($productId, $locale, $fields);

        return [];
    }

    public function delete(int $productId, string $locale): void
    {
        $this->translations->delete($productId, $locale);
    }

    /**
     * Asks Gemini to translate a product's text into $locale. Nothing is
     * saved: the admin reviews the fields, then saves.
     *
     * @param array<string, mixed> $product name, short_description, long_description (the original text)
     * @return array<string, string> name, short_description, long_description (only what came back)
     * @throws RuntimeException when the language isn't available or Gemini fails / answers with nothing usable
     */
    public function suggest(array $product, string $locale): array
    {
        $language = $this->locales()[$locale] ?? null;
        if ($language === null) {
            throw new RuntimeException($this->translator->trans('That language is not available for translations.'));
        }
        $from = $this->translator->languages()[$this->baseLocale()] ?? $this->baseLocale();

        $prompt = <<<PROMPT
            You translate product text for an online store.

            Translate the fields below from {$from} ({$this->baseLocale()}) into {$language} ({$locale}).
            Keep the meaning, tone and any brand or product names; do not add or drop information.
            Keep the HTML of long_description (p, ul, ol, li, strong, em, a, br) and translate only the text.

            name: {$product['name']}
            short_description: {$product['short_description']}
            long_description: {$product['long_description']}

            Reply with only a JSON object with the keys name, short_description and long_description.
            Do not wrap it in code fences.
            PROMPT;

        $fields = $this->parseFields($this->gemini->generateText($prompt));
        if ($fields === []) {
            throw new RuntimeException($this->translator->trans('The AI did not return a usable translation. Please try again.'));
        }

        return $fields;
    }

    /**
     * Pulls the fields out of the model's reply, tolerating code fences or
     * prose around the JSON; the description goes through the sanitizer.
     *
     * @return array<string, string>
     */
    private function parseFields(string $reply): array
    {
        $start = strpos($reply, '{');
        $end = strrpos($reply, '}');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }
        $decoded = json_decode(substr($reply, $start, $end - $start + 1), true);
        if (!is_array($decoded)) {
            return [];
        }

        $fields = [];
        foreach (ProductTranslationRepository::FIELDS as $key) {
            if (isset($decoded[$key]) && is_string($decoded[$key]) && trim($decoded[$key]) !== '') {
                $fields[$key] = trim($decoded[$key]);
            }
        }
        if (isset($fields['long_description'])) {
            $fields['long_description'] = $this->sanitizer->clean($fields['long_description']);
        }

        return $fields;
    }

    /** Sanitized HTML; an editor left with nothing but empty markup counts as empty. */
    private function cleanDescription(string $html): string
    {
        $clean = $this->sanitizer->clean($html);

        return trim(strip_tags($clean)) === '' ? '' : $clean;
    }
}
