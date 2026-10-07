<?php

declare(strict_types=1);

namespace App\View;

use App\I18n\LocaleFormat;
use App\I18n\Translator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Translation helpers, in application views and (sandboxed) theme parts:
 *   'Your cart'|trans                         translated text (App\I18n\Translator)
 *   'Hello, {name}'|trans({name: x})          with placeholders (escaped like any output)
 *   '{count} item'|trans_plural(n)            one/other plural forms; {count} is n
 *   t('Your cart') / t_plural('{count} item', n, {...})   the same as functions
 *   date|local_date('medium', true)           "7 Oct 2026, 14:30" in the active language
 *   number|local_number(2)                    "1,234.50" / "1 234,50"
 * Application views only:
 *   html_lang()                               <html lang> of the active language ("pt-PT")
 *   current_locale()                          catalog code ("en", "pt")
 *   languages()                               [{code, name, html_lang, active}] for language pickers
 *   i18n_js()                                 JSON of the strings JavaScript needs (translations/js-keys.php)
 */
final class TranslationExtension extends AbstractExtension
{
    /** @var list<string>|null */
    private ?array $jsKeys = null;

    public function __construct(
        private readonly Translator $translator,
        private readonly string $jsKeysFile = '',
    ) {
    }

    public function getFilters(): array
    {
        return self::filters($this->translator);
    }

    public function getFunctions(): array
    {
        return array_merge(self::functions($this->translator), [
            new TwigFunction('html_lang', fn (): string => $this->translator->htmlLang()),
            new TwigFunction('current_locale', $this->translator->locale(...)),
            new TwigFunction('languages', $this->languages(...)),
            new TwigFunction('i18n_js', $this->jsonForScripts(...)),
        ]);
    }

    /**
     * The filters themes may use as well (see Theme::SANDBOX_FILTERS).
     *
     * @return list<TwigFilter>
     */
    public static function filters(Translator $translator): array
    {
        return [
            new TwigFilter('trans', /** @param array<array-key, mixed> $params */ static fn (?string $message,array $params = []): string => $translator->trans((string) $message, $params)),
            new TwigFilter('trans_plural', /** @param array<array-key, mixed> $params */ static fn (?string $message,int|float|string|null $count, array $params = []): string => $translator->transPlural((string) $message, (float) $count == (int) $count ? (int) $count : (float) $count, $params)),
            new TwigFilter('local_date', static fn (mixed $date, string $style = 'medium', bool $time = false): string => LocaleFormat::date(self::dateValue($date), $translator->intlLocale(), $style, $time)),
            new TwigFilter('local_number', static fn (float|int|string|null $number, int $decimals = 0): string => LocaleFormat::number($number, $translator->intlLocale(), $decimals)),
        ];
    }

    /**
     * The functions themes may use as well (see Theme::SANDBOX_FUNCTIONS).
     *
     * @return list<TwigFunction>
     */
    public static function functions(Translator $translator): array
    {
        return [
            new TwigFunction('t', /** @param array<array-key, mixed> $params */ static fn (?string $message,array $params = []): string => $translator->trans((string) $message, $params)),
            new TwigFunction('t_plural', /** @param array<array-key, mixed> $params */ static fn (?string $message,int|float|string|null $count, array $params = []): string => $translator->transPlural((string) $message, (float) $count == (int) $count ? (int) $count : (float) $count, $params)),
        ];
    }

    /** @return list<array{code: string, name: string, html_lang: string, active: bool}> */
    private function languages(): array
    {
        $list = [];
        foreach ($this->translator->languages() as $code => $name) {
            $list[] = [
                'code'      => $code,
                'name'      => $name,
                'html_lang' => $this->translator->htmlLang($code),
                'active'    => $code === $this->translator->locale(),
            ];
        }

        return $list;
    }

    private function jsonForScripts(): string
    {
        if ($this->jsKeys === null) {
            $keys = $this->jsKeysFile !== '' && is_file($this->jsKeysFile) ? require $this->jsKeysFile : [];
            $this->jsKeys = is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
        }

        return (string) json_encode((object) $this->translator->export($this->jsKeys), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function dateValue(mixed $date): \DateTimeInterface|string|int|null
    {
        return $date instanceof \DateTimeInterface || is_string($date) || is_int($date) ? $date : null;
    }
}
