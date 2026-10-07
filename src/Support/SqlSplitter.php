<?php

declare(strict_types=1);

namespace App\Support;

use Generator;

/**
 * Splits a SQL script into statements without being fooled by ";" inside
 * quoted strings, `identifiers` or comments. Works line by line, so a large
 * dump is never loaded at once. Used by `bin/console db:restore`.
 * (DELIMITER blocks — stored routines, triggers — are not supported.)
 */
final class SqlSplitter
{
    /** Quoted strings, identifiers and comments — everything a ";" may hide in. */
    private const OPAQUE = '/\'(?:[^\'\\\\]++|\\\\.|\'\')*+\'|"(?:[^"\\\\]++|\\\\.|"")*+"|`[^`]*+`|--[^\n]*+|#[^\n]*+|\/\*(?!!).*?\*\//s';

    /**
     * @param iterable<string> $lines lines including their line breaks
     * @return Generator<int, string> statements without the trailing ";"
     */
    public static function statements(iterable $lines): Generator
    {
        $buffer = '';
        foreach ($lines as $line) {
            $buffer .= $line;
            if (!str_ends_with(rtrim($line), ';')) {
                continue;
            }
            $code = self::code($buffer);
            if ($code === null || !str_ends_with(rtrim($code), ';')) {
                continue; // the ";" is inside a string or comment that goes on
            }
            $statement = self::trim($buffer);
            if ($statement !== '') {
                yield $statement;
            }
            $buffer = '';
        }

        $statement = self::trim($buffer);
        if ($statement !== '' && trim((string) self::code($buffer)) !== '') {
            yield $statement;
        }
    }

    /** @return list<string> */
    public static function split(string $sql): array
    {
        $lines = preg_split('/(?<=\n)/', $sql) ?: [];

        return iterator_to_array(self::statements($lines), false);
    }

    /** The SQL with strings/identifiers/comments removed, or null while one is still open. */
    private static function code(string $sql): ?string
    {
        $code = preg_replace(self::OPAQUE, ' ', $sql);
        if ($code === null) {
            return null;
        }
        // An opening quote or comment without its end: the statement isn't finished.
        foreach (["'", '"', '`'] as $quote) {
            if (str_contains($code, $quote)) {
                return null;
            }
        }

        return str_contains($code, '/*') && !str_contains($code, '/*!') ? null : $code;
    }

    /** Removes leading comment lines, surrounding space and the final ";". */
    private static function trim(string $statement): string
    {
        $statement = trim($statement);
        while (preg_match('/^(--[^\n]*|#[^\n]*)(\n|$)/', $statement) === 1) {
            $statement = ltrim((string) preg_replace('/^[^\n]*(\n|$)/', '', $statement, 1));
        }

        return rtrim(rtrim($statement), ';');
    }
}
