<?php

declare(strict_types=1);

namespace App\Support;

use NumberFormatter;

/**
 * Currency helpers: minor-unit exponents (ISO 4217), conversion to and from
 * the provider's smallest unit, and display formatting in a locale ("€12.50"
 * in English, "12,50 €" in Portuguese). Uses ext-intl when it is loaded,
 * otherwise a small symbol table.
 */
final class Money
{
    /** Currencies whose minor unit isn't 1/100 (everything else has 2 decimals). */
    private const EXPONENTS = [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0,
        'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
    ];

    /** Fallback symbols when ext-intl isn't available: [symbol, symbol before the amount]. */
    private const SYMBOLS = [
        'EUR' => ['€', true], 'USD' => ['$', true], 'GBP' => ['£', true], 'JPY' => ['¥', true],
        'CNY' => ['CN¥', true], 'KRW' => ['₩', true], 'INR' => ['₹', true], 'AUD' => ['A$', true],
        'CAD' => ['CA$', true], 'NZD' => ['NZ$', true], 'BRL' => ['R$', true], 'MXN' => ['MX$', true],
        'CHF' => ['CHF ', true], 'SEK' => [' kr', false], 'NOK' => [' kr', false], 'DKK' => [' kr', false],
        'PLN' => [' zł', false], 'CZK' => [' Kč', false], 'HUF' => [' Ft', false], 'RON' => [' lei', false],
        'TRY' => ['₺', true], 'ILS' => ['₪', true], 'ZAR' => ['R', true], 'THB' => ['฿', true],
    ];

    /**
     * Without ext-intl: locales that write the symbol after the amount
     * ("12,50 €"), keyed by language.
     */
    private const SYMBOL_AFTER = ['pt', 'es', 'fr', 'de', 'it', 'nl', 'pl', 'cs', 'sk', 'fi', 'sv', 'da', 'nb', 'ro', 'hu'];

    private const LOCALE = 'en';

    /** @var array<string, array{symbol: string, before: bool, decimals: int, decimal: string, group: string}> */
    private static array $specs = [];

    public static function exponent(string $currency): int
    {
        return self::EXPONENTS[strtoupper($currency)] ?? 2;
    }

    /** Amount in the currency's smallest unit (cents, or yen for JPY). */
    public static function toMinor(float $amount, string $currency): int
    {
        return (int) round($amount * 10 ** self::exponent($currency));
    }

    public static function fromMinor(int|float $minor, string $currency): float
    {
        return round($minor / 10 ** self::exponent($currency), self::exponent($currency));
    }

    /** True when $paid covers $expected (within half a minor unit). */
    public static function covers(float $paid, float $expected, string $currency): bool
    {
        return $paid + 0.5 / 10 ** self::exponent($currency) >= $expected;
    }

    /** "€12.50", "¥1,200", "12.50 kr"; in $locale (ICU, e.g. "pt_PT") "12,50 €" */
    public static function format(float $amount, string $currency, ?string $locale = null): string
    {
        $currency = strtoupper($currency);
        $locale = $locale ?: self::LOCALE;
        if (extension_loaded('intl')) {
            $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
            $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, self::exponent($currency));
            $formatted = $formatter->formatCurrency($amount, $currency);
            if ($formatted !== false) {
                return $formatted;
            }
        }

        $spec = self::spec($currency, $locale);
        $number = number_format($amount, $spec['decimals'], $spec['decimal'], $spec['group']);

        return $spec['before'] ? $spec['symbol'] . $number : $number . $spec['symbol'];
    }

    /**
     * How to write the currency in $locale, for JavaScript that formats
     * amounts the same way: symbol (with any spacing), whether it goes
     * before the amount, decimals, decimal point and thousands separator.
     *
     * @return array{symbol: string, before: bool, decimals: int, decimal: string, group: string}
     */
    public static function spec(string $currency, ?string $locale = null): array
    {
        $currency = strtoupper($currency);
        $locale = $locale ?: self::LOCALE;
        $key = $currency . '|' . $locale;
        if (isset(self::$specs[$key])) {
            return self::$specs[$key];
        }

        $decimals = self::exponent($currency);
        [$symbol, $before] = self::SYMBOLS[$currency] ?? [' ' . $currency, false];
        $language = strtolower((string) preg_replace('/[_-].*$/', '', $locale));
        $decimal = '.';
        $group = ',';
        if ($language !== 'en') {
            [$decimal, $group] = [',', in_array($language, ['pt', 'fr', 'pl', 'cs', 'sk', 'fi', 'sv', 'nb'], true) ? ' ' : '.'];
            if (in_array($language, self::SYMBOL_AFTER, true)) {
                [$symbol, $before] = [' ' . trim($symbol), false];
            }
        }

        if (extension_loaded('intl')) {
            $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
            $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $decimals);
            $decimal = (string) $formatter->getSymbol(NumberFormatter::MONETARY_SEPARATOR_SYMBOL);
            $group = (string) $formatter->getSymbol(NumberFormatter::MONETARY_GROUPING_SEPARATOR_SYMBOL);
            $sample = $formatter->formatCurrency(1, $currency);
            $number = number_format(1, $decimals, $decimal, $group);
            $at = $sample !== false ? strpos($sample, $number) : false;
            if ($sample !== false && $at !== false) {
                $before = $at > 0;
                $symbol = $before ? substr($sample, 0, $at) : substr($sample, $at + strlen($number));
            }
        }

        return self::$specs[$key] = ['symbol' => $symbol, 'before' => $before, 'decimals' => $decimals, 'decimal' => $decimal, 'group' => $group];
    }
}
