<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SimplePdf;
use Tests\TestCase;

final class SimplePdfTest extends TestCase
{
    public function testWidthUsesHelveticaMetrics(): void
    {
        $pdf = new SimplePdf();
        $this->assertSame(22.78, round($pdf->width('Hello', 10), 2));
        $this->assertTrue($pdf->width('Hello', 10, true) > $pdf->width('Hello', 10), 'bold is wider');
        $this->assertSame($pdf->width('e', 10), $pdf->width('é', 10), 'accents measure like their base letter');
    }

    public function testOutputIsAStructurallyValidPdf(): void
    {
        $pdf = new SimplePdf();
        $pdf->text(50, 60, 'Fatura (teste) \\ ação €', 12, true);
        $pdf->line(50, 70, 500, 70);
        $pdf->addPage();
        $pdf->text(50, 60, 'Page two');
        $out = $pdf->output();

        $this->assertTrue(str_starts_with($out, '%PDF-1.4'));
        $this->assertTrue(str_ends_with(rtrim($out), '%%EOF'));
        $this->assertSame(2, $pdf->pageCount());
        $this->assertStringContainsString('/Count 2', $out);

        // Every xref entry must point at "<n> 0 obj".
        preg_match('/startxref\n(\d+)/', $out, $m);
        $xref = substr($out, (int) $m[1]);
        preg_match_all('/(\d{10}) 00000 n/', $xref, $offsets);
        $this->assertTrue(count($offsets[1]) >= 8, 'catalog, pages, 2 fonts, 2 pages with content');
        foreach ($offsets[1] as $i => $offset) {
            $this->assertSame(($i + 1) . ' 0 obj', substr($out, (int) $offset, strlen(($i + 1) . ' 0 obj')));
        }
    }

    public function testWrapSplitsLongTextAndKeepsParagraphs(): void
    {
        $pdf = new SimplePdf();
        $lines = $pdf->wrap('one two three four five six seven eight', $pdf->width('one two three', 10) + 1, 10);
        $this->assertTrue(count($lines) >= 3);
        foreach ($lines as $line) {
            $this->assertTrue($pdf->width($line, 10) <= $pdf->width('one two three', 10) + 1);
        }
        $this->assertCount(2, $pdf->wrap("a\nb", 500, 10));
    }
}
