<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * CSV helpers for the admin exports and the product import.
 *
 * Written files are UTF-8 with a byte-order mark (so Excel picks the right
 * encoding) and every cell that a spreadsheet would treat as a formula
 * (starting with = + - @, a tab or a carriage return — plain negative
 * numbers excepted) is prefixed with a single quote — "CSV injection". read() undoes that prefix, so an exported
 * file can be imported again unchanged.
 */
final class Csv
{
    public const BOM = "\xEF\xBB\xBF";

    private const FORMULA_START = ['=', '+', '-', '@', "\t", "\r"];

    /** A cell value that is safe to open in a spreadsheet. */
    public static function cell(mixed $value): string
    {
        $text = match (true) {
            $value === null  => '',
            is_bool($value)  => $value ? '1' : '0',
            is_float($value) => self::number($value),
            default          => (string) $value,
        };

        // A plain number ("-5.00") can't be a formula, so negative amounts stay numbers.
        $dangerous = $text !== '' && in_array($text[0], self::FORMULA_START, true) && preg_match('/^-?\d+(\.\d+)?$/', $text) !== 1;

        return $dangerous ? "'" . $text : $text;
    }

    /** Reverses cell(): "'=1+1" → "=1+1". Other values are returned unchanged. */
    public static function uncell(string $text): string
    {
        return strlen($text) > 1 && $text[0] === "'" && in_array($text[1], self::FORMULA_START, true) ? substr($text, 1) : $text;
    }

    /**
     * Writes one row (values pass through cell()).
     *
     * @param resource          $handle
     * @param array<int, mixed> $values
     */
    public static function writeRow($handle, array $values): void
    {
        fputcsv($handle, array_map(self::cell(...), array_values($values)), ',', '"', '');
    }

    /**
     * A php://temp stream (kept in memory up to 2 MB, then on disk) holding
     * the BOM and the header row, ready for writeRow().
     *
     * @param list<string> $header
     * @return resource
     */
    public static function open(array $header)
    {
        $handle = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'w+b');
        if ($handle === false) {
            throw new RuntimeException('Could not open a temporary stream.');
        }
        fwrite($handle, self::BOM);
        self::writeRow($handle, $header);

        return $handle;
    }

    /**
     * Parses CSV text: strips the BOM, detects "," or ";" from the first line,
     * skips blank lines and removes the formula-protection prefix.
     *
     * @return list<list<string>>
     * @throws RuntimeException when the text has more than $maxRows rows (header included)
     */
    public static function parse(string $text, int $maxRows = PHP_INT_MAX): array
    {
        return array_values(self::parseLines($text, $maxRows));
    }

    /**
     * Like parse(), keyed by the 1-based line each row starts on (for error messages).
     *
     * @return array<int, list<string>>
     */
    public static function parseLines(string $text, int $maxRows = PHP_INT_MAX): array
    {
        if (str_starts_with($text, self::BOM)) {
            $text = substr($text, 3);
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            // Excel's "CSV" (not "CSV UTF-8") saves Windows-1252.
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($text, "\n") ?: '';
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) {
            throw new RuntimeException('Could not open a temporary stream.');
        }
        fwrite($handle, $text);
        rewind($handle);

        $rows = [];
        $line = 1;
        $position = 0;
        try {
            while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $start = $line;
                $next = (int) ftell($handle);
                $line += substr_count($text, "\n", $position, $next - $position);
                $position = $next;
                if ($row === [null] || implode('', array_map('strval', $row)) === '') {
                    continue; // blank line
                }
                if (count($rows) >= $maxRows) {
                    throw new RuntimeException('The file has too many rows (max ' . ($maxRows - 1) . ' plus the header).');
                }
                $rows[$start] = array_map(static fn ($v) => self::uncell(trim((string) $v)), $row);
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /** "12.5" → "12.50"; whole numbers keep two decimals too (prices). */
    private static function number(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
