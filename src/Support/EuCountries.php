<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The 27 EU member states: ISO code ↔ the names people type in a Country field.
 * Greece is "GR" as a country and "EL" in VAT numbers; both are understood.
 */
final class EuCountries
{
    /** @var array<string, list<string>> ISO code => lower-case names without accents */
    private const NAMES = [
        'AT' => ['austria', 'osterreich'], 'BE' => ['belgium', 'belgique', 'belgie', 'belgica'], 'BG' => ['bulgaria'],
        'HR' => ['croatia', 'hrvatska', 'croacia'], 'CY' => ['cyprus', 'chipre', 'kypros'],
        'CZ' => ['czechia', 'czech republic', 'republica checa', 'chequia', 'tchequia'], 'DK' => ['denmark', 'danmark', 'dinamarca'],
        'EE' => ['estonia', 'eesti', 'estonia'], 'FI' => ['finland', 'suomi', 'finlandia'], 'FR' => ['france', 'franca'],
        'DE' => ['germany', 'deutschland', 'alemanha', 'alemania'], 'GR' => ['greece', 'hellas', 'grecia'],
        'HU' => ['hungary', 'magyarorszag', 'hungria'], 'IE' => ['ireland', 'eire', 'irlanda'], 'IT' => ['italy', 'italia'],
        'LV' => ['latvia', 'latvija', 'letonia'], 'LT' => ['lithuania', 'lietuva', 'lituania'], 'LU' => ['luxembourg', 'luxemburgo'],
        'MT' => ['malta'], 'NL' => ['netherlands', 'nederland', 'holland', 'paises baixos', 'holanda'], 'PL' => ['poland', 'polska', 'polonia'],
        'PT' => ['portugal'], 'RO' => ['romania'], 'SK' => ['slovakia', 'slovensko', 'eslovaquia'], 'SI' => ['slovenia', 'slovenija', 'eslovenia'],
        'ES' => ['spain', 'espana', 'espanha'], 'SE' => ['sweden', 'sverige', 'suecia'],
    ];

    /** ISO country code of an EU member state from what was typed ("Portugal", "pt", "Deutschland", "EL"), or null. */
    public static function code(string $country): ?string
    {
        $needle = self::fold($country);
        if ($needle === '') {
            return null;
        }
        $upper = strtoupper($needle);
        if ($upper === 'EL') {
            return 'GR';
        }
        if (isset(self::NAMES[$upper])) {
            return $upper;
        }
        foreach (self::NAMES as $code => $names) {
            if (in_array($needle, $names, true)) {
                return $code;
            }
        }

        return null;
    }

    /** The prefix VAT numbers of that country start with (EL for Greece, otherwise the ISO code). */
    public static function vatPrefix(string $code): string
    {
        return $code === 'GR' ? 'EL' : $code;
    }

    /** ISO code for a VAT-number prefix ("EL" → "GR"), or null when it is no EU state. */
    public static function fromVatPrefix(string $prefix): ?string
    {
        return self::code($prefix);
    }

    private static function fold(string $text): string
    {
        $text = mb_strtolower(trim($text));

        return strtr($text, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y']);
    }
}
