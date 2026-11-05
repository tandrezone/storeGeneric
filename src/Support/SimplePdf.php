<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A tiny PDF writer for documents made of text and rules (invoices): A4, the
 * built-in Helvetica / Helvetica-Bold fonts (WinAnsi, so Western European
 * accents and € work), several pages, no images. Coordinates are in points
 * from the top-left corner of the page (y grows downwards).
 */
final class SimplePdf
{
    public const WIDTH = 595.28;
    public const HEIGHT = 841.89;

    /** Helvetica advance widths (per 1000 em) for ASCII 32..126. */
    private const REGULAR = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];
    private const BOLD = [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584];

    /** Accented letters → their base letter, for measuring only. */
    private const BASE = [
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Ç' => 'C', 'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y',
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
        'ß' => 's', 'Œ' => 'O', 'œ' => 'o', 'Š' => 'S', 'š' => 's', 'Ž' => 'Z', 'ž' => 'z',
    ];

    /** @var list<string> page content streams (uncompressed) */
    private array $pages = [''];
    private int $current = 0;

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->current = count($this->pages) - 1;
    }

    /** Text width in points. */
    public function width(string $text, float $size, bool $bold = false): float
    {
        $table = $bold ? self::BOLD : self::REGULAR;
        $total = 0;
        foreach (str_split($this->encode(strtr($text, self::BASE))) as $char) {
            $code = ord($char);
            $total += $code >= 32 && $code <= 126 ? $table[$code - 32] : ($code === 0x80 ? 556 : 556);
        }

        return $total * $size / 1000;
    }

    /** @param 'left'|'right'|'center' $align x is the left edge, right edge or centre */
    public function text(float $x, float $y, string $text, float $size = 10.0, bool $bold = false, string $align = 'left', ?array $color = null): void
    {
        if ($text === '') {
            return;
        }
        $w = $this->width($text, $size, $bold);
        $x = match ($align) {
            'right'  => $x - $w,
            'center' => $x - $w / 2,
            default  => $x,
        };
        $rgb = $color ?? [0, 0, 0];
        $this->pages[$this->current] .= sprintf(
            "BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n",
            $bold ? 'F2' : 'F1', $size, $rgb[0], $rgb[1], $rgb[2], $x, self::HEIGHT - $y, $this->escape($this->encode($text))
        );
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $thickness = 0.6, ?array $color = null): void
    {
        $rgb = $color ?? [0.7, 0.7, 0.7];
        $this->pages[$this->current] .= sprintf(
            "%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n",
            $rgb[0], $rgb[1], $rgb[2], $thickness, $x1, self::HEIGHT - $y1, $x2, self::HEIGHT - $y2
        );
    }

    /**
     * Greedy word wrap to a width.
     *
     * @return list<string>
     */
    public function wrap(string $text, float $maxWidth, float $size, bool $bold = false): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [''] as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($paragraph)) ?: [] as $word) {
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($line !== '' && $this->width($try, $size, $bold) > $maxWidth) {
                    $lines[] = $line;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /** The finished document. */
    public function output(): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        foreach (array_keys($this->pages) as $i) {
            $kids[] = (5 + $i * 2) . ' 0 R';
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($this->pages) . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        foreach ($this->pages as $i => $content) {
            $pageId = 5 + $i * 2;
            $stream = gzcompress($content, 6);
            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                self::WIDTH, self::HEIGHT, $pageId + 1
            );
            $objects[$pageId + 1] = "<< /Length " . strlen((string) $stream) . " /Filter /FlateDecode >>\nstream\n" . $stream . "\nendstream";
        }

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        ksort($objects);
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref
0 ' . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer
<< /Size ' . (count($objects) + 1) . ' /Root 1 0 R >>
startxref
' . $xref . "\n%%EOF\n";

        return $pdf;
    }

    /** UTF-8 → Windows-1252 (what WinAnsiEncoding means); other characters become "?". */
    private function encode(string $text): string
    {
        return (string) mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }

    private function escape(string $text): string
    {
        return strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }
}
