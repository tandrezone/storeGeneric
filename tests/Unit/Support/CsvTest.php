<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Csv;
use RuntimeException;
use Tests\TestCase;

final class CsvTest extends TestCase
{
    public function testFormulaCellsArePrefixed(): void
    {
        foreach (['=1+1', '+1', '-1+2', '@SUM(A1)', "\tx", "\rx", '=HYPERLINK("http://evil")'] as $value) {
            $this->assertSame("'" . $value, Csv::cell($value), 'cell ' . json_encode($value));
        }
    }

    public function testOrdinaryCellsAreUnchanged(): void
    {
        $this->assertSame('Green tea', Csv::cell('Green tea'));
        $this->assertSame('a=b', Csv::cell('a=b'));
        $this->assertSame('-5', Csv::cell('-5'), 'plain negative numbers stay numbers');
        $this->assertSame('-12.50', Csv::cell('-12.50'));
        $this->assertSame('12.50', Csv::cell(12.5));
        $this->assertSame('3', Csv::cell(3));
        $this->assertSame('', Csv::cell(null));
        $this->assertSame('1', Csv::cell(true));
    }

    public function testUncellReversesTheProtection(): void
    {
        $this->assertSame('=1+1', Csv::uncell("'=1+1"));
        $this->assertSame("'quoted", Csv::uncell("'quoted"), 'a quote before normal text is kept');
        $this->assertSame("'", Csv::uncell("'"));
    }

    public function testWriteThenParseRoundTrip(): void
    {
        $rows = [
            ['sku', 'name', 'note'],
            ['A-1', 'Tea, green', "Line 1\nLine 2"],
            ['A-2', 'He said "hi"', '=cmd|calc'],
            ['A-3', 'Crème brûlée', ''],
        ];
        $handle = Csv::open($rows[0]);
        foreach (array_slice($rows, 1) as $row) {
            Csv::writeRow($handle, $row);
        }
        rewind($handle);
        $text = (string) stream_get_contents($handle);

        $this->assertTrue(str_starts_with($text, Csv::BOM), 'starts with a UTF-8 BOM');
        $this->assertStringContainsString("'=cmd|calc", $text);
        $this->assertSame($rows, Csv::parse($text));
    }

    public function testParseDetectsSemicolonsAndSkipsBlankLines(): void
    {
        $this->assertSame([['sku', 'price'], ['A', '1,50']], Csv::parse("sku;price\r\n\r\nA;1,50\r\n"));
    }

    public function testParseConvertsWindows1252(): void
    {
        $latin = "name\n" . mb_convert_encoding('Crème', 'Windows-1252', 'UTF-8') . "\n";
        $this->assertSame([['name'], ['Crème']], Csv::parse($latin));
    }

    public function testParseRowLimit(): void
    {
        $this->assertCount(3, Csv::parse("a\n1\n2\n", 3));
        $this->assertThrows(RuntimeException::class, static fn () => Csv::parse("a\n1\n2\n3\n", 3), 'too many rows');
    }
}
