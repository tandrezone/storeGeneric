<?php

declare(strict_types=1);

namespace App\I18n;

/**
 * Translates user-visible text. Catalogs are PHP files in translations/
 * (<code>.php, e.g. en.php, pt.php) returning an array whose keys are the
 * English source strings:
 *
 *   'Your cart is empty.'  => 'O seu carrinho está vazio.',
 *   'Hello, {name}!'       => 'Olá, {name}!',
 *   '{count} item'         => ['one' => '{count} artigo', 'other' => '{count} artigos'],
 *
 * Keys starting with "@" describe the language: @name (shown in language
 * pickers), @html_lang (<html lang>, hreflang) and @intl (ICU locale for
 * dates, numbers and money). A missing translation falls back to English,
 * then to the key itself, so untranslated text still reads correctly.
 *
 * The active locale is per request (set by LocaleMiddleware for the
 * storefront visitor or the admin user); withLocale() switches it for a
 * moment, e.g. to send an email in the language of the order.
 */
final class Translator
{
    public const DEFAULT_LOCALE = 'en';

    /** Languages whose plural forms aren't "one when n = 1, other otherwise". */
    private const PLURAL_RULES = [
        'fr' => 'zeroOrOne',
        'pt_BR' => 'zeroOrOne',
    ];

    private string $locale = self::DEFAULT_LOCALE;
    /** @var array<string, array<string, string|array<string, string>>> */
    private array $catalogs = [];
    /** @var list<string>|null */
    private ?array $available = null;

    public function __construct(private readonly string $directory)
    {
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /** Switches the active locale; an unsupported one falls back to English. */
    public function setLocale(?string $locale): void
    {
        $this->locale = $this->normalize($locale) ?? self::DEFAULT_LOCALE;
    }

    /**
     * Runs $callback with another active locale (null or unsupported: keep
     * the current one), then restores the previous one.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withLocale(?string $locale, callable $callback): mixed
    {
        $previous = $this->locale;
        $this->locale = $this->normalize($locale) ?? $previous;
        try {
            return $callback();
        } finally {
            $this->locale = $previous;
        }
    }

    /**
     * Translates $message and fills in {placeholders}.
     *
     * @param array<array-key, mixed> $params name => value (with or without braces)
     */
    public function trans(string $message, array $params = [], ?string $locale = null): string
    {
        $translation = $this->lookup($message, $locale);
        if (is_array($translation)) {
            $translation = $translation['other'] ?? $translation['one'] ?? $message;
        }

        return self::interpolate($translation ?? $message, $params);
    }

    /**
     * Translates a message with plural forms (one/other) for $count, which
     * is also available as {count}.
     *
     * @param array<array-key, mixed> $params
     */
    public function transPlural(string $message, int|float $count, array $params = [], ?string $locale = null): string
    {
        $locale = $this->normalize($locale) ?? $this->locale;
        $translation = $this->lookup($message, $locale) ?? $message;
        if (is_array($translation)) {
            $form = $this->pluralForm($locale, $count);
            $translation = $translation[$form] ?? $translation['other'] ?? reset($translation);
        }

        return self::interpolate((string) $translation, $params + ['count' => $count]);
    }

    /** "one" or "other" for $count in $locale. */
    public function pluralForm(string $locale, int|float $count): string
    {
        $rule = self::PLURAL_RULES[$this->intlLocale($locale)] ?? self::PLURAL_RULES[$locale] ?? 'oneOnly';

        return match ($rule) {
            'zeroOrOne' => abs($count) < 2 ? 'one' : 'other',
            default     => $count == 1 ? 'one' : 'other',
        };
    }

    /**
     * Locales with a catalog in translations/, English first.
     *
     * @return list<string>
     */
    public function available(): array
    {
        if ($this->available === null) {
            $codes = [];
            foreach (glob($this->directory . '/*.php') ?: [] as $file) {
                $code = basename($file, '.php');
                if (preg_match('/^[a-z]{2,3}(_[A-Za-z0-9]{2,8})?$/', $code)) {
                    $codes[] = $code;
                }
            }
            sort($codes);
            $this->available = array_values(array_unique(array_merge([self::DEFAULT_LOCALE], $codes)));
        }

        return $this->available;
    }

    /** @return array<string, string> code => language name in that language ("Português") */
    public function languages(): array
    {
        $languages = [];
        foreach ($this->available() as $code) {
            $languages[$code] = $this->meta($code, 'name', $code);
        }

        return $languages;
    }

    /**
     * The supported locale for a code like "pt", "pt-PT", "pt_PT" or "PT",
     * or null when it isn't supported.
     */
    public function normalize(?string $locale): ?string
    {
        $locale = trim((string) $locale);
        if ($locale === '' || !preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/', $locale)) {
            return null;
        }

        $parts = preg_split('/[_-]/', $locale) ?: [$locale];
        $language = strtolower($parts[0]);
        $available = $this->available();
        if (isset($parts[1])) {
            $full = $language . '_' . strtoupper($parts[1]);
            if (in_array($full, $available, true)) {
                return $full;
            }
        }

        return in_array($language, $available, true) ? $language : null;
    }

    public function isSupported(?string $locale): bool
    {
        return $this->normalize($locale) !== null;
    }

    /** BCP 47 tag for <html lang> and hreflang, e.g. "pt-PT". */
    public function htmlLang(?string $locale = null): string
    {
        $locale = $this->normalize($locale) ?? $this->locale;

        return $this->meta($locale, 'html_lang', str_replace('_', '-', $locale));
    }

    /** ICU locale for IntlDateFormatter / NumberFormatter, e.g. "pt_PT". */
    public function intlLocale(?string $locale = null): string
    {
        $locale = $this->normalize($locale) ?? $this->locale;

        return $this->meta($locale, 'intl', $locale);
    }

    /**
     * Translations of $keys in the active locale (for JavaScript): plain
     * strings, or {one, other} arrays for plural messages.
     *
     * @param list<string> $keys
     * @return array<string, string|array<string, string>>
     */
    public function export(array $keys): array
    {
        $strings = [];
        foreach ($keys as $key) {
            $strings[$key] = $this->lookup($key, null) ?? $key;
        }

        return $strings;
    }

    /**
     * The raw catalog of $locale ([] when there is none).
     *
     * @return array<string, string|array<string, string>>
     */
    public function catalog(string $locale): array
    {
        if (!isset($this->catalogs[$locale])) {
            $file = $this->directory . '/' . $locale . '.php';
            $catalog = preg_match('/^[a-z]{2,3}(_[A-Za-z0-9]{2,8})?$/', $locale) && is_file($file) ? require $file : [];
            $this->catalogs[$locale] = is_array($catalog) ? $catalog : [];
        }

        return $this->catalogs[$locale];
    }

    /** @return string|array<string, string>|null */
    private function lookup(string $message, ?string $locale): string|array|null
    {
        $locale = $locale === null ? $this->locale : ($this->normalize($locale) ?? $this->locale);
        $chain = [$locale];
        if (str_contains($locale, '_')) {
            $chain[] = strstr($locale, '_', true);
        }
        $chain[] = self::DEFAULT_LOCALE;

        foreach (array_unique($chain) as $code) {
            $translation = $this->catalog($code)[$message] ?? null;
            if ($translation !== null && $translation !== '' && $translation !== []) {
                return $translation;
            }
        }

        return null;
    }

    private function meta(string $locale, string $name, string $default): string
    {
        $value = $this->catalog($locale)['@' . $name] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /** @param array<array-key, mixed> $params */
    private static function interpolate(string $text, array $params): string
    {
        if ($params === [] || !str_contains($text, '{')) {
            return $text;
        }

        $replace = [];
        foreach ($params as $name => $value) {
            $name = trim((string) $name, '{}');
            $replace['{' . $name . '}'] = is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
        }

        return strtr($text, $replace);
    }
}
