<?php

declare(strict_types=1);

namespace App\I18n;

use DateTimeImmutable;
use DateTimeInterface;
use IntlDateFormatter;
use NumberFormatter;
use Throwable;

/**
 * Dates and numbers written the way a locale expects ("7 Oct 2026" /
 * "07/10/2026", "1,234.5" / "1234,5"). Uses ext-intl when it is loaded,
 * otherwise a small table of common conventions.
 */
final class LocaleFormat
{
    /**
     * Fallbacks without ext-intl: [decimal point, thousands separator, short, medium, long date].
     * Month names would be English, so other languages write dates as numbers.
     */
    private const FALLBACK = [
        'en' => ['.', ',', 'd/m/Y', 'j M Y', 'j F Y'],
        'pt' => [',', ' ', 'd/m/Y', 'd/m/Y', 'd/m/Y'],
        'es' => [',', '.', 'd/m/Y', 'd/m/Y', 'd/m/Y'],
        'fr' => [',', ' ', 'd/m/Y', 'd/m/Y', 'd/m/Y'],
        'de' => [',', '.', 'd.m.Y', 'd.m.Y', 'd.m.Y'],
        'it' => [',', '.', 'd/m/Y', 'd/m/Y', 'd/m/Y'],
        'nl' => [',', '.', 'd-m-Y', 'd-m-Y', 'd-m-Y'],
    ];

    private const STYLES = ['short', 'medium', 'long'];

    /**
     * @param string $style "short" (07/10/2026), "medium" (7 Oct 2026) or "long" (7 October 2026)
     */
    public static function date(DateTimeInterface|string|int|null $value, string $locale, string $style = 'medium', bool $withTime = false): string
    {
        $date = self::toDate($value);
        if ($date === null) {
            return '';
        }
        $style = in_array($style, self::STYLES, true) ? $style : 'medium';

        if (extension_loaded('intl')) {
            $types = ['short' => IntlDateFormatter::SHORT, 'medium' => IntlDateFormatter::MEDIUM, 'long' => IntlDateFormatter::LONG];
            $formatter = new IntlDateFormatter(
                $locale,
                $types[$style],
                $withTime ? IntlDateFormatter::SHORT : IntlDateFormatter::NONE,
                $date->getTimezone()
            );
            if ($style === 'short') {
                // ICU's short dates have two-digit years ("07/10/26"); keep four.
                $formatter->setPattern(str_replace(['yyyy', 'yy'], ['y', 'y'], (string) $formatter->getPattern()));
            }
            $formatted = $formatter->format($date);
            if (is_string($formatted)) {
                return $formatted;
            }
        }

        $spec = self::fallback($locale);
        $pattern = $spec[['short' => 2, 'medium' => 3, 'long' => 4][$style]];

        return $date->format($pattern . ($withTime ? ' H:i' : ''));
    }

    public static function number(float|int|string|null $value, string $locale, int $decimals = 0): string
    {
        $value = (float) $value;
        if (extension_loaded('intl')) {
            $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
            $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
            $formatted = $formatter->format($value);
            if (is_string($formatted)) {
                return $formatted;
            }
        }

        $spec = self::fallback($locale);

        return number_format($value, $decimals, $spec[0], $spec[1]);
    }

    /**
     * Decimal point and thousands separator of $locale (for JavaScript).
     *
     * @return array{decimal: string, group: string}
     */
    public static function separators(string $locale): array
    {
        if (extension_loaded('intl')) {
            $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);

            return [
                'decimal' => (string) $formatter->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL),
                'group'   => (string) $formatter->getSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL),
            ];
        }
        $spec = self::fallback($locale);

        return ['decimal' => $spec[0], 'group' => $spec[1]];
    }

    /** @return array{0: string, 1: string, 2: string, 3: string, 4: string} */
    private static function fallback(string $locale): array
    {
        $language = strtolower((string) preg_replace('/[_-].*$/', '', $locale));

        return self::FALLBACK[$language] ?? self::FALLBACK['en'];
    }

    private static function toDate(DateTimeInterface|string|int|null $value): ?DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return is_int($value) || ctype_digit($value)
                ? (new DateTimeImmutable('@' . $value))->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                : new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
