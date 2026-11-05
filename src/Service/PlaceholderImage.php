<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Paths;
use App\Theme\Theme;
use GdImage;

/**
 * Generates (and caches under public/assets/images/generated) a placeholder
 * card with the product's name in the middle, for products without a photo.
 *
 * The card is styled for the active theme: its theme.json "placeholder" block
 * picks colours, font, decorative motif and text effect (see style()). Themes
 * without one (e.g. uploaded themes) get a card built from the colours and
 * font family in their style.css. The file name hashes the name, theme and
 * style, so renaming a product or switching theme produces a fresh card.
 *
 * Drawing happens at 2× and is scaled down, which anti-aliases every shape.
 */
final class PlaceholderImage
{
    public const PUBLIC_DIR = 'assets/images/generated';
    private const WIDTH = 800;
    private const HEIGHT = 600;
    private const SCALE = 2;
    private const VERSION = 'v3';

    /** "font" in a theme's placeholder block => file in resources/fonts. */
    public const FONTS = [
        'sans'         => 'Roboto-Regular.ttf',
        'sans-bold'    => 'DejaVuSans-Bold.ttf',
        'serif'        => 'P052-Roman.otf',
        'serif-italic' => 'P052-Italic.otf',
        'serif-bold'   => 'P052-Bold.otf',
        'mono'         => 'DejaVuSansMono.ttf',
        'mono-bold'    => 'DejaVuSansMono-Bold.ttf',
        'round'        => 'URWGothic-Book.otf',
        'round-bold'   => 'URWGothic-Demi.otf',
    ];

    public const MOTIFS = [
        'package', 'frame', 'corners', 'rule', 'grid', 'stars', 'scanlines', 'neon', 'horizon',
        'dots', 'slices', 'stripes', 'blobs', 'waves', 'sun', 'dunes', 'peaks', 'leaves',
        'falling-leaves', 'petals', 'toadstool', 'beans',
    ];

    private const DEFAULT_STYLE = [
        'background' => ['#f2f3f6', '#e4e5ea'],
        'text'       => '#34373e',
        'accent'     => '#c4c7ce',
        'accent2'    => '#9aa0ab',
        'surface'    => '#ffffff',
        'font'       => 'sans',
        'motif'      => 'package',
        'uppercase'  => false,
        'effect'     => 'none',
        'prefix'     => '',
        'suffix'     => '',
        'text_y'     => 300,
    ];

    private GdImage $image;
    /** @var array<string, mixed> */
    private array $style;
    private int $seed = 0;

    public function __construct(private readonly Paths $paths, private readonly Theme $theme)
    {
    }

    /**
     * Path (relative to public/) for the product's placeholder, generating it if needed.
     *
     * @param array<string, mixed> $product
     */
    public function pathFor(array $product): string
    {
        $file = $this->fileFor($product);
        if (!is_file($file)) {
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0775, true);
            }
            $this->render($this->nameOf($product), (int) ($product['id'] ?? 0), $file);
            $this->removeStale((int) ($product['id'] ?? 0), $file);
        }

        return self::PUBLIC_DIR . '/' . basename($file);
    }

    /**
     * Cached placeholder path, or '' if it hasn't been generated yet (never generates).
     *
     * @param array<string, mixed> $product
     */
    public function cachedPathFor(array $product): string
    {
        $file = $this->fileFor($product);

        return is_file($file) ? self::PUBLIC_DIR . '/' . basename($file) : '';
    }

    /** @param array<string, mixed> $product */
    public function forget(array $product): void
    {
        $file = $this->fileFor($product);
        if (is_file($file)) {
            unlink($file);
        }
    }

    /**
     * Renders a card for any name and style straight to a file (previews,
     * tests). $style uses the theme.json "placeholder" keys; missing keys
     * take the defaults.
     *
     * @param array<string, mixed> $style
     */
    public function renderTo(string $name, array $style, string $destination, int $seed = 1): void
    {
        $this->style = $this->normalize($style);
        $this->draw($name, $seed);
        imagejpeg($this->image, $destination, 88);
    }

    /**
     * The active theme's placeholder style: theme.json "placeholder" block over
     * colours/font taken from its style.css, over the neutral defaults.
     *
     * @return array<string, mixed>
     */
    public function style(): array
    {
        $manifest = $this->theme->manifest();
        $own = is_array($manifest['placeholder'] ?? null) ? $manifest['placeholder'] : [];

        return $this->normalize($own + $this->styleFromCss($this->theme->cssVariables()));
    }

    /** @param array<string, mixed> $product */
    private function nameOf(array $product): string
    {
        // base_name: the original name, so every language shares one placeholder file.
        return trim((string) ($product['base_name'] ?? $product['name'] ?? '')) ?: 'Product';
    }

    /** @param array<string, mixed> $product */
    private function fileFor(array $product): string
    {
        $key = self::VERSION . '|' . $this->theme->name() . '|' . json_encode($this->style()) . '|' . $this->nameOf($product);

        return $this->paths->public(self::PUBLIC_DIR . '/' . (int) ($product['id'] ?? 0) . '-' . substr(md5($key), 0, 8) . '.jpg');
    }

    /** Older cards of the same product (previous name or theme). */
    private function removeStale(int $id, string $keep): void
    {
        foreach (glob(dirname($keep) . '/' . $id . '-*.jpg') ?: [] as $old) {
            if ($old !== $keep && preg_match('/\/' . $id . '-[0-9a-f]{8}\.jpg$/', $old)) {
                @unlink($old);
            }
        }
    }

    private function render(string $name, int $seed, string $destination): void
    {
        $this->style = $this->style();
        $this->draw($name, $seed);

        // Write to a temp file and rename into place: rename() is atomic, so
        // a concurrent request never serves a half-written file.
        $tmp = $destination . '.' . bin2hex(random_bytes(4)) . '.tmp';
        imagejpeg($this->image, $tmp, 88);
        rename($tmp, $destination);
    }

    private function draw(string $name, int $seed): void
    {
        $this->seed = $seed;
        $this->image = imagecreatetruecolor(self::WIDTH * self::SCALE, self::HEIGHT * self::SCALE);
        imagealphablending($this->image, true);

        $this->background();
        $motif = (string) $this->style['motif'];
        $method = 'motif' . str_replace(' ', '', ucwords(str_replace('-', ' ', $motif)));
        if (method_exists($this, $method)) {
            $this->{$method}();
        }
        $this->text($name);

        $small = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagecopyresampled($small, $this->image, 0, 0, 0, 0, self::WIDTH, self::HEIGHT, self::WIDTH * self::SCALE, self::HEIGHT * self::SCALE);
        $this->image = $small;
    }

    // ------------------------------------------------------------ style

    /**
     * @param array<string, mixed> $style
     * @return array<string, mixed>
     */
    private function normalize(array $style): array
    {
        $style += self::DEFAULT_STYLE;
        $bg = $style['background'];
        $style['background'] = is_array($bg) ? array_values($bg) + [1 => $bg[0] ?? '#ffffff'] : [(string) $bg, (string) $bg];
        if (!isset(self::FONTS[$style['font']])) {
            $style['font'] = 'sans';
        }
        if (!in_array($style['motif'], self::MOTIFS, true)) {
            $style['motif'] = 'corners';
        }
        ksort($style);

        return $style;
    }

    /**
     * Fallback for themes without a placeholder block.
     *
     * @param array<string, string> $vars CSS custom properties from :root
     * @return array<string, mixed>
     */
    private function styleFromCss(array $vars): array
    {
        if (!isset($vars['--color-bg'], $vars['--color-text'])) {
            return [];
        }
        $family = strtolower(($vars['--font-theme'] ?? '') . ' ' . ($vars['--font-display'] ?? $vars['--font-serif'] ?? $vars['--font-body'] ?? ''));
        $font = match (true) {
            str_contains($family, 'mono')                                 => 'mono',
            str_contains($family, 'rounded') || str_contains($family, 'nunito') => 'round',
            str_contains($family, 'georgia') || str_contains($family, 'serif') && !str_contains($family, 'sans-serif') => 'serif',
            default                                                       => 'sans',
        };

        return [
            'background' => [$vars['--color-surface'] ?? $vars['--color-bg'], $vars['--color-bg']],
            'text'       => $vars['--color-text'],
            'accent'     => $vars['--color-accent'] ?? $vars['--color-text'],
            'accent2'    => $vars['--color-accent-2'] ?? $vars['--color-accent'] ?? $vars['--color-text'],
            'surface'    => $vars['--color-surface'] ?? $vars['--color-bg'],
            'font'       => $font,
            'motif'      => 'corners',
        ];
    }

    // ------------------------------------------------------------ drawing helpers

    /** @return array{0: int, 1: int, 2: int} */
    private function rgb(string $color): array
    {
        $color = trim($color);
        if (preg_match('/^#([0-9a-f]{3})$/i', $color, $m)) {
            $color = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }
        if (preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})/i', $color, $m)) {
            return [hexdec($m[1]), hexdec($m[2]), hexdec($m[3])];
        }
        if (preg_match('/rgba?\(\s*(\d+)\D+(\d+)\D+(\d+)/i', $color, $m)) {
            return [(int) $m[1], (int) $m[2], (int) $m[3]];
        }

        return [128, 128, 128];
    }

    /** @param float $opacity 0 (invisible) … 1 (solid) */
    private function color(string $key, float $opacity = 1.0): int
    {
        $value = $this->style[$key] ?? $key;
        [$r, $g, $b] = $this->rgb(is_string($value) ? $value : $key);
        $alpha = (int) round(127 * (1 - max(0.0, min(1.0, $opacity))));

        return imagecolorallocatealpha($this->image, $r, $g, $b, $alpha);
    }

    private function s(float $v): int
    {
        return (int) round($v * self::SCALE);
    }

    private function rect(float $x1, float $y1, float $x2, float $y2, int $color): void
    {
        imagefilledrectangle($this->image, $this->s($x1), $this->s($y1), $this->s($x2), $this->s($y2), $color);
    }

    private function circle(float $cx, float $cy, float $r, int $color): void
    {
        imagefilledellipse($this->image, $this->s($cx), $this->s($cy), $this->s(2 * $r), $this->s(2 * $r), $color);
    }

    private function line(float $x1, float $y1, float $x2, float $y2, float $width, int $color): void
    {
        imagesetthickness($this->image, max(1, $this->s($width)));
        imageline($this->image, $this->s($x1), $this->s($y1), $this->s($x2), $this->s($y2), $color);
        imagesetthickness($this->image, 1);
    }

    /** Outline rectangle with straight, even edges. */
    private function box(float $x1, float $y1, float $x2, float $y2, float $width, int $color): void
    {
        $this->rect($x1, $y1, $x2, $y1 + $width, $color);
        $this->rect($x1, $y2 - $width, $x2, $y2, $color);
        $this->rect($x1, $y1 + $width, $x1 + $width, $y2 - $width, $color);
        $this->rect($x2 - $width, $y1 + $width, $x2, $y2 - $width, $color);
    }

    private function roundRect(float $x1, float $y1, float $x2, float $y2, float $r, int $color): void
    {
        $r = min($r, ($x2 - $x1) / 2, ($y2 - $y1) / 2);
        $this->rect($x1 + $r, $y1, $x2 - $r, $y2, $color);
        $this->rect($x1, $y1 + $r, $x1 + $r - 0.5, $y2 - $r, $color);
        $this->rect($x2 - $r + 0.5, $y1 + $r, $x2, $y2 - $r, $color);
        foreach ([[$x1 + $r, $y1 + $r, 180], [$x2 - $r, $y1 + $r, 270], [$x2 - $r, $y2 - $r, 0], [$x1 + $r, $y2 - $r, 90]] as [$cx, $cy, $start]) {
            imagefilledarc($this->image, $this->s($cx), $this->s($cy), $this->s(2 * $r), $this->s(2 * $r), $start, $start + 90, $color, IMG_ARC_PIE);
        }
    }

    /** A stroke made of overlapping dots: round caps/joins, follows any path. */
    private function stroke(array $points, float $width, int $color): void
    {
        $r = max(1, $this->s($width / 2));
        for ($i = 1; $i < count($points); $i++) {
            [$x1, $y1] = $points[$i - 1];
            [$x2, $y2] = $points[$i];
            $steps = max(1, (int) (hypot($x2 - $x1, $y2 - $y1) * self::SCALE / max(1, $r / 2)));
            for ($t = 0; $t <= $steps; $t++) {
                imagefilledellipse($this->image, $this->s($x1 + ($x2 - $x1) * $t / $steps), $this->s($y1 + ($y2 - $y1) * $t / $steps), 2 * $r, 2 * $r, $color);
            }
        }
    }

    /** @param list<array{0: float, 1: float}> $points */
    private function polygon(array $points, int $color): void
    {
        $flat = [];
        foreach ($points as [$x, $y]) {
            $flat[] = $this->s($x);
            $flat[] = $this->s($y);
        }
        imagefilledpolygon($this->image, $flat, $color);
    }

    /** Deterministic pseudo-random number in [0, 1) for decoration (same card every time). */
    private function rand(int $n): float
    {
        return (crc32($this->seed . ':' . $n) % 100000) / 100000;
    }

    /** Leaf shape: two arcs from base to tip. */
    private function leaf(float $x, float $y, float $length, float $width, float $angle, int $color): void
    {
        $points = [];
        $cos = cos($angle);
        $sin = sin($angle);
        for ($i = 0; $i <= 20; $i++) {
            $t = $i / 20;
            $points[] = [$t * $length, sin($t * M_PI) * $width / 2];
        }
        for ($i = 19; $i >= 1; $i--) {
            $t = $i / 20;
            $points[] = [$t * $length, -sin($t * M_PI) * $width / 2];
        }
        $this->polygon(array_map(static fn (array $p) => [$x + $p[0] * $cos - $p[1] * $sin, $y + $p[0] * $sin + $p[1] * $cos], $points), $color);
    }

    // ------------------------------------------------------------ background and text

    private function background(): void
    {
        [$top, $bottom] = $this->style['background'];
        [$r1, $g1, $b1] = $this->rgb((string) $top);
        [$r2, $g2, $b2] = $this->rgb((string) $bottom);
        $height = self::HEIGHT * self::SCALE;
        for ($y = 0; $y < $height; $y++) {
            $t = $y / ($height - 1);
            $color = imagecolorallocate($this->image, (int) ($r1 + ($r2 - $r1) * $t), (int) ($g1 + ($g2 - $g1) * $t), (int) ($b1 + ($b2 - $b1) * $t));
            imageline($this->image, 0, $y, self::WIDTH * self::SCALE, $y, $color);
        }
    }

    private function text(string $name): void
    {
        $font = $this->paths->resources('fonts/' . self::FONTS[$this->style['font']]);
        $text = $this->style['uppercase'] ? mb_strtoupper($name) : $name;
        $text = $this->style['prefix'] . $text . $this->style['suffix'];
        $maxWidth = (float) ($this->style['text_width'] ?? 560);

        // Biggest size (72…30) whose wrapped lines fit: up to 2 rows while
        // the text is still large, then up to 4.
        for ($size = 72; $size >= 30; $size -= 2) {
            $lines = $this->wrap($text, $font, $size, $maxWidth);
            if (count($lines) <= ($size >= 50 ? 2 : 4) && max(array_map(fn ($l) => $this->textWidth($l, $font, $size), $lines)) <= $maxWidth) {
                break;
            }
        }
        $lines = array_slice($lines, 0, 4);

        $lineHeight = $size * 1.3;
        $blockHeight = (count($lines) - 1) * $lineHeight + $size;
        $centerY = (float) $this->style['text_y'];
        $top = $centerY - $blockHeight / 2;
        $widest = max(array_map(fn ($l) => $this->textWidth($l, $font, $size), $lines));

        if (isset($this->style['label']) && is_array($this->style['label'])) {
            $this->label($widest, $top, $blockHeight, $size);
        }

        foreach ($lines as $i => $line) {
            $width = $this->textWidth($line, $font, $size);
            $x = (self::WIDTH - $width) / 2;
            $baseline = $top + $size * 0.85 + $i * $lineHeight;
            if (!empty($this->style['highlight'])) {
                $this->rect($x - 10, $baseline - $size * 0.45, $x + $width + 10, $baseline + $size * 0.18, $this->color((string) $this->style['highlight'], 0.85));
            }
            $this->effect($line, $font, $size, $x, $baseline);
            $this->glyphs($line, $font, $size, $x, $baseline, $this->color('text'));
        }
    }

    /** Box behind the name (candy, bold, …): label {fill, border, shadow, radius, border_width, padding}. */
    private function label(float $width, float $top, float $height, int $size): void
    {
        $label = $this->style['label'];
        $pad = (float) ($label['padding'] ?? 34);
        $x1 = (self::WIDTH - $width) / 2 - $pad;
        $x2 = (self::WIDTH + $width) / 2 + $pad;
        $y1 = $top - $pad * 0.75;
        $y2 = $top + $height + $pad * 0.75 + $size * 0.1;
        $radius = (float) ($label['radius'] ?? 0);
        $offset = (float) ($label['shadow_offset'] ?? 10);
        if (!empty($label['shadow'])) {
            $this->roundRect($x1 + $offset, $y1 + $offset, $x2 + $offset, $y2 + $offset, $radius, $this->color((string) $label['shadow']));
        }
        $border = (float) ($label['border_width'] ?? 0);
        if ($border > 0 && !empty($label['border'])) {
            $this->roundRect($x1, $y1, $x2, $y2, $radius, $this->color((string) $label['border']));
        }
        $this->roundRect($x1 + $border, $y1 + $border, $x2 - $border, $y2 - $border, max(0, $radius - $border), $this->color((string) ($label['fill'] ?? 'surface')));
    }

    private function effect(string $line, string $font, int $size, float $x, float $baseline): void
    {
        switch ($this->style['effect']) {
            case 'soft':
                $this->glyphs($line, $font, $size, $x, $baseline + 3, $this->color('#000000', 0.18));
                break;
            case 'hard':
                $this->glyphs($line, $font, $size, $x + 5, $baseline + 5, $this->color('accent'));
                break;
            case 'letterpress':
                $this->glyphs($line, $font, $size, $x + 2.5, $baseline + 2.5, $this->color('accent2', 0.55));
                break;
            case 'glow':
                foreach ([9, 6, 3] as $radius) {
                    for ($a = 0; $a < 12; $a++) {
                        $angle = $a * M_PI / 6;
                        $this->glyphs($line, $font, $size, $x + cos($angle) * $radius, $baseline + sin($angle) * $radius, $this->color('accent', 0.07));
                    }
                }
                break;
        }
    }

    private function glyphs(string $line, string $font, int $size, float $x, float $baseline, int $color): void
    {
        imagettftext($this->image, $size * self::SCALE * 0.75, 0, $this->s($x), $this->s($baseline), $color, $font, $line);
    }

    private function textWidth(string $text, string $font, int $size): float
    {
        $box = imagettfbbox($size * 0.75, 0, $font, $text);

        return (float) abs($box[2] - $box[0]);
    }

    /** @return list<string> */
    private function wrap(string $text, string $font, int $size, float $maxWidth): array
    {
        $lines = [];
        $current = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($current === '' || $this->textWidth($candidate, $font, $size) <= $maxWidth) {
                $current = $candidate;
                continue;
            }
            $lines[] = $current;
            $current = $word;
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        if (count($lines) > 4) {
            $lines = array_slice($lines, 0, 4);
            $lines[3] = rtrim($lines[3], ' .,;:') . '…';
        }

        return $lines ?: [''];
    }

    // ------------------------------------------------------------ motifs (800×600 coordinates)

    /** Simple package outline above the name. */
    private function motifPackage(): void
    {
        $c = $this->color('accent');
        $this->box(340, 110, 460, 215, 6, $c);
        $this->rect(340, 140, 460, 146, $c);
        $this->rect(392, 110, 408, 146, $c);
    }

    /** Double inset border with diamond ornaments (classic / editorial). */
    private function motifFrame(): void
    {
        $this->box(26, 26, 774, 574, 3, $this->color('accent'));
        $this->box(38, 38, 762, 562, 1, $this->color('accent', 0.7));
        $c = $this->color('accent');
        foreach ([[400, 32], [400, 568], [32, 300], [768, 300]] as [$x, $y]) {
            $this->polygon([[$x, $y - 11], [$x + 11, $y], [$x, $y + 11], [$x - 11, $y]], $c);
        }
        $y = (float) $this->style['text_y'];
        foreach ([$y - 110, $y + 110] as $ry) {
            $this->rect(330, $ry - 0.75, 385, $ry + 0.75, $c);
            $this->rect(415, $ry - 0.75, 470, $ry + 0.75, $c);
            $this->polygon([[400, $ry - 6], [406, $ry], [400, $ry + 6], [394, $ry]], $c);
        }
    }

    /** Corner brackets and a short accent rule (clean / Scandinavian). */
    private function motifCorners(): void
    {
        $c = $this->color('accent');
        $len = 56;
        $w = 3;
        foreach ([[48, 48, 1, 1], [752, 48, -1, 1], [48, 552, 1, -1], [752, 552, -1, -1]] as [$x, $y, $dx, $dy]) {
            $this->rect(min($x, $x + $dx * $len), min($y, $y + $dy * $w), max($x, $x + $dx * $len), max($y, $y + $dy * $w), $c);
            $this->rect(min($x, $x + $dx * $w), min($y, $y + $dy * $len), max($x, $x + $dx * $w), max($y, $y + $dy * $len), $c);
        }
        $y = (float) $this->style['text_y'] + 105;
        $this->rect(370, $y, 430, $y + 3, $this->color('accent2'));
    }

    /** Hairlines above and below the name, nothing else (minimal). */
    private function motifRule(): void
    {
        $c = $this->color('accent');
        $y = (float) $this->style['text_y'];
        $this->rect(360, $y - 100, 440, $y - 99, $c);
        $this->rect(360, $y + 99, 440, $y + 100, $c);
    }

    /** Ruled drafting grid with a title block (blueprint). */
    private function motifGrid(): void
    {
        $minor = $this->color('accent2', 0.10);
        $major = $this->color('accent2', 0.28);
        for ($x = 0; $x <= self::WIDTH; $x += 20) {
            $this->rect($x, 0, $x + ($x % 100 === 0 ? 1.5 : 0.75), self::HEIGHT, $x % 100 === 0 ? $major : $minor);
        }
        for ($y = 0; $y <= self::HEIGHT; $y += 20) {
            $this->rect(0, $y, self::WIDTH, $y + ($y % 100 === 0 ? 1.5 : 0.75), $y % 100 === 0 ? $major : $minor);
        }
        $edge = $this->color('text', 0.85);
        $this->box(30, 30, 770, 570, 2, $edge);
        $this->box(560, 500, 770, 570, 2, $edge);
        $this->rect(560, 534, 770, 536, $edge);
        $this->rect(660, 500, 662, 570, $edge);
        // Dimension line under the name.
        $y = (float) $this->style['text_y'] + 110;
        $this->rect(220, $y, 580, $y + 1.5, $this->color('accent'));
        $this->rect(220, $y - 10, 221.5, $y + 10, $this->color('accent'));
        $this->rect(578.5, $y - 10, 580, $y + 10, $this->color('accent'));
    }

    /** Star field with soft nebula glows (space / night). */
    private function motifStars(): void
    {
        foreach ([['accent', 150, 180, 230], ['accent2', 650, 430, 210]] as [$key, $cx, $cy, $radius]) {
            for ($r = $radius; $r > 10; $r -= 8) {
                $this->circle($cx, $cy, $r, $this->color($key, 0.035));
            }
        }
        for ($i = 0; $i < 170; $i++) {
            $size = 0.6 + $this->rand($i * 3 + 2) ** 3 * 2.6;
            $this->circle($this->rand($i * 3) * self::WIDTH, $this->rand($i * 3 + 1) * self::HEIGHT, $size, $this->color('#ffffff', 0.35 + $this->rand($i * 7) * 0.6));
        }
        // A few four-point sparkles.
        foreach ([[120, 470, 10], [690, 110, 13], [560, 520, 7], [250, 90, 8]] as [$x, $y, $r]) {
            $c = $this->color('#ffffff', 0.9);
            $this->polygon([[$x, $y - $r], [$x + $r * 0.18, $y - $r * 0.18], [$x + $r, $y], [$x + $r * 0.18, $y + $r * 0.18], [$x, $y + $r], [$x - $r * 0.18, $y + $r * 0.18], [$x - $r, $y], [$x - $r * 0.18, $y - $r * 0.18]], $c);
        }
    }

    /** CRT scanlines, vignette and a window bar (terminal). */
    private function motifScanlines(): void
    {
        for ($y = 0; $y < self::HEIGHT; $y += 4) {
            $this->rect(0, $y, self::WIDTH, $y + 1.5, $this->color('#000000', 0.25));
        }
        for ($i = 0; $i < 40; $i++) {
            $this->box($i * 2, $i * 2, self::WIDTH - $i * 2, self::HEIGHT - $i * 2, 2, $this->color('#000000', 0.035));
        }
        $border = $this->color('accent', 0.55);
        $this->box(40, 40, 760, 560, 2, $border);
        $this->rect(40, 40, 760, 74, $this->color('accent', 0.14));
        foreach ([62, 86, 110] as $i => $x) {
            $this->circle($x, 57, 6, $this->color($i === 0 ? 'accent2' : 'accent', 0.7));
        }
    }

    /** Glowing neon outline in both accents (dark tech themes). */
    private function motifNeon(): void
    {
        $outline = static fn (float $inset): array => [
            [$inset, $inset + 40], [$inset + 40, $inset], [800 - $inset, $inset], [800 - $inset, 560 - $inset],
            [760 - $inset, 600 - $inset], [$inset, 600 - $inset], [$inset, $inset + 40],
        ];
        foreach ([16, 10, 6] as $w) {
            $this->stroke($outline(46), $w, $this->color('accent', 0.08));
        }
        $this->stroke($outline(46), 2.5, $this->color('accent', 0.95));
        foreach ([12, 6] as $w) {
            $this->stroke($outline(62), $w, $this->color('accent2', 0.06));
        }
        $this->stroke($outline(62), 1.2, $this->color('accent2', 0.6));
        // Soft glow behind the name.
        $y = (float) $this->style['text_y'];
        for ($r = 260; $r > 20; $r -= 12) {
            $this->circle(400, $y, $r, $this->color('accent', 0.012));
        }
    }

    /** Striped retro sun over a perspective grid (synthwave). */
    private function motifHorizon(): void
    {
        $horizon = 420;
        // Sun: accent → accent2, with gaps cut into its lower half.
        [$r1, $g1, $b1] = $this->rgb((string) $this->style['accent']);
        [$r2, $g2, $b2] = $this->rgb((string) $this->style['accent2']);
        $cx = 400;
        $cy = 400;
        $radius = 150;
        for ($y = $cy - $radius; $y < $horizon; $y += 1) {
            $rel = ($y - ($cy - $radius)) / (2 * $radius);
            if ($y > $cy - 70 && (int) (($y - $cy) / 6) % 3 === 0) {
                continue;
            }
            $half = sqrt(max(0, $radius ** 2 - ($y - $cy) ** 2));
            $c = imagecolorallocatealpha($this->image, (int) ($r1 + ($r2 - $r1) * $rel), (int) ($g1 + ($g2 - $g1) * $rel), (int) ($b1 + ($b2 - $b1) * $rel), 50);
            $this->rect($cx - $half, $y, $cx + $half, $y + 1, $c);
        }
        // Ground and grid.
        $this->rect(0, $horizon, self::WIDTH, self::HEIGHT, $this->color('#000000', 0.45));
        $grid = $this->color('accent2', 0.75);
        for ($i = 0; $i < 9; $i++) {
            $y = $horizon + 4 * (1.55 ** $i);
            if ($y < self::HEIGHT) {
                $this->rect(0, $y, self::WIDTH, $y + 1 + $i * 0.25, $grid);
            }
        }
        for ($i = -10; $i <= 10; $i++) {
            $this->line(400 + $i * 22, $horizon, 400 + $i * 150, self::HEIGHT + 40, 1.2, $grid);
        }
        $this->rect(0, $horizon - 1, self::WIDTH, $horizon + 1.5, $this->color('accent', 0.9));
    }

    /** Polka dots (candy). */
    private function motifDots(): void
    {
        $c = $this->color('accent2', 0.16);
        for ($row = 0, $y = 20; $y < self::HEIGHT + 30; $row++, $y += 52) {
            for ($x = ($row % 2) * 30 + 10; $x < self::WIDTH + 30; $x += 60) {
                $this->circle($x, $y, 9, $c);
            }
        }
    }

    /** Citrus wheels in the corners (citrus). */
    private function motifSlices(): void
    {
        foreach ([[70, 80, 110, 'accent'], [740, 520, 130, 'accent2'], [760, 70, 55, 'accent2'], [60, 545, 60, 'accent']] as [$cx, $cy, $r, $key]) {
            $this->circle($cx, $cy, $r, $this->color($key, 0.75));
            $this->circle($cx, $cy, $r * 0.86, $this->color('#ffffff', 0.85));
            $this->circle($cx, $cy, $r * 0.8, $this->color($key, 0.35));
            for ($a = 0; $a < 10; $a++) {
                $angle = $a * M_PI / 5;
                $this->line($cx, $cy, $cx + cos($angle) * $r * 0.8, $cy + sin($angle) * $r * 0.8, max(2, $r * 0.04), $this->color('#ffffff', 0.85));
            }
            $this->circle($cx, $cy, $r * 0.12, $this->color('#ffffff', 0.9));
        }
    }

    /** Hazard-stripe bands top and bottom (bold). */
    private function motifStripes(): void
    {
        $dark = $this->color('text');
        $accent = $this->color('accent2');
        foreach ([[0, 46], [554, 600]] as [$y1, $y2]) {
            $this->rect(0, $y1, self::WIDTH, $y2, $this->color('#ffd23f'));
            for ($x = -60; $x < self::WIDTH + 60; $x += 56) {
                $this->polygon([[$x, $y2], [$x + 28, $y2], [$x + 28 + ($y2 - $y1), $y1], [$x + ($y2 - $y1), $y1]], $dark);
            }
            $this->rect(0, $y1 === 0 ? $y2 : $y1 - 6, self::WIDTH, $y1 === 0 ? $y2 + 6 : $y1, $dark);
        }
        $this->circle(110, 160, 36, $accent);
        $this->circle(700, 440, 22, $this->color('accent'));
    }

    /** Large soft overlapping circles (soft pastel themes). */
    private function motifBlobs(): void
    {
        $this->circle(90, 70, 210, $this->color('accent', 0.13));
        $this->circle(210, -20, 130, $this->color('accent2', 0.14));
        $this->circle(730, 560, 230, $this->color('accent2', 0.15));
        $this->circle(600, 640, 140, $this->color('accent', 0.12));
        $this->circle(740, 120, 40, $this->color('accent', 0.18));
        $this->circle(70, 520, 26, $this->color('accent2', 0.2));
    }

    /** Layered waves along the bottom (ocean). */
    private function motifWaves(): void
    {
        foreach ([[455, 22, 0.12, 'accent', 0.0], [490, 18, 0.17, 'accent', 1.7], [530, 14, 0.24, 'accent', 3.1]] as [$base, $amp, $opacity, $key, $phase]) {
            $points = [[0, self::HEIGHT]];
            for ($x = 0; $x <= self::WIDTH; $x += 10) {
                $points[] = [$x, $base + sin($x / 70 + $phase) * $amp];
            }
            $points[] = [self::WIDTH, self::HEIGHT];
            $this->polygon($points, $this->color($key, $opacity));
        }
        foreach ([[120, 110, 7], [148, 92, 4], [680, 140, 5], [660, 165, 9]] as [$x, $y, $r]) {
            $this->circle($x, $y, $r, $this->color('accent', 0.2));
        }
    }

    /** Setting sun with horizon bands (sunset). */
    private function motifSun(): void
    {
        for ($r = 300; $r > 160; $r -= 14) {
            $this->circle(400, 600, $r, $this->color('accent', 0.03));
        }
        $this->circle(400, 600, 160, $this->color('accent', 0.6));
        $this->circle(400, 600, 120, $this->color('accent2', 0.25));
        foreach ([[520, 4], [545, 6], [572, 8]] as [$y, $h]) {
            $this->rect(0, $y, self::WIDTH, $y + $h, $this->color((string) $this->style['background'][1], 0.9));
        }
    }

    /** Sand dunes under a pale sun (desert). */
    private function motifDunes(): void
    {
        $this->circle(560, 110, 44, $this->color('accent', 0.5));
        $this->circle(560, 110, 60, $this->color('accent', 0.12));
        foreach ([[450, 40, 0.25, 'accent', 0.6, 120], [500, 30, 0.35, 'accent', 2.2, 160], [545, 24, 0.5, 'accent2', 4.0, 110]] as [$base, $amp, $opacity, $key, $phase, $len]) {
            $points = [[0, self::HEIGHT]];
            for ($x = 0; $x <= self::WIDTH; $x += 10) {
                $points[] = [$x, $base + sin($x / $len + $phase) * $amp];
            }
            $points[] = [self::WIDTH, self::HEIGHT];
            $this->polygon($points, $this->color($key, $opacity));
        }
    }

    /** Snowy mountain ridge (arctic). */
    private function motifPeaks(): void
    {
        $ridges = [
            [[0, 470], [110, 380], [190, 430], [300, 330], [400, 420], [520, 350], [640, 440], [720, 390], [800, 430]],
            [[0, 520], [140, 450], [260, 500], [380, 440], [500, 505], [610, 455], [800, 510]],
        ];
        foreach ($ridges as $i => $ridge) {
            $this->polygon(array_merge($ridge, [[800, 600], [0, 600]]), $this->color($i === 0 ? 'accent2' : 'accent', $i === 0 ? 0.22 : 0.3));
        }
        // Snow caps on the back ridge.
        foreach ([[110, 380], [300, 330], [520, 350], [720, 390]] as [$x, $y]) {
            $this->polygon([[$x, $y], [$x + 30, $y + 22], [$x + 12, $y + 18], [$x, $y + 26], [$x - 12, $y + 18], [$x - 30, $y + 22]], $this->color('#ffffff', 0.9));
        }
        foreach ([[90, 90], [170, 140], [640, 80], [720, 160], [560, 150]] as $i => [$x, $y]) {
            $c = $this->color('accent', 0.35);
            for ($a = 0; $a < 3; $a++) {
                $angle = $a * M_PI / 3;
                $this->line($x - cos($angle) * 10, $y - sin($angle) * 10, $x + cos($angle) * 10, $y + sin($angle) * 10, 1.6, $c);
            }
        }
    }

    /** Leafy sprigs in two corners (forest, succulent). */
    private function motifLeaves(): void
    {
        $sprig = function (float $x, float $y, float $angle, float $scale): void {
            $stem = $this->color('accent', 0.55);
            $len = 220 * $scale;
            $this->stroke([[$x, $y], [$x + cos($angle) * $len, $y + sin($angle) * $len]], 3 * $scale, $stem);
            for ($i = 1; $i <= 5; $i++) {
                $t = $i / 5.6;
                $px = $x + cos($angle) * $len * $t;
                $py = $y + sin($angle) * $len * $t;
                $size = (1.1 - $t * 0.5) * 80 * $scale;
                $key = $i % 2 === 0 ? 'accent' : 'accent2';
                $this->leaf($px, $py, $size, $size * 0.45, $angle - 0.75, $this->color($key === 'accent2' && $i === 5 ? 'accent2' : 'accent', 0.32 + $i * 0.04));
                $this->leaf($px, $py, $size, $size * 0.45, $angle + 0.75, $this->color('accent', 0.38 + $i * 0.04));
            }
        };
        $sprig(-10, 610, -0.95, 1.1);
        $sprig(810, -10, M_PI - 0.95, 0.95);
        $this->circle(705, 520, 8, $this->color('accent2', 0.5));
        $this->circle(735, 500, 5, $this->color('accent2', 0.4));
    }

    /** Scattered falling leaves in warm tones (autumn). */
    private function motifFallingLeaves(): void
    {
        $tones = ['accent', 'accent2', '#d9a13b'];
        $spots = [[70, 90], [180, 40], [720, 60], [640, 150], [90, 480], [210, 550], [700, 470], [600, 560], [40, 280], [760, 300]];
        foreach ($spots as $i => [$x, $y]) {
            $size = 50 + $this->rand($i) * 40;
            $angle = $this->rand($i + 50) * M_PI * 2;
            $color = $this->color($tones[$i % 3], 0.55 + $this->rand($i + 9) * 0.25);
            $this->leaf($x, $y, $size, $size * 0.55, $angle, $color);
            $this->line($x, $y, $x - cos($angle) * 14, $y - sin($angle) * 14, 2, $color);
        }
    }

    /** Cherry blossoms (sakura). */
    private function motifPetals(): void
    {
        $this->stroke([[-10, 120], [120, 95], [210, 120], [300, 70]], 7, $this->color('text', 0.35));
        $this->stroke([[120, 95], [170, 40]], 4, $this->color('text', 0.35));
        $this->stroke([[810, 520], [700, 540], [610, 510], [540, 545]], 7, $this->color('text', 0.35));
        $blossoms = [[120, 95, 26], [210, 120, 20], [300, 70, 24], [170, 40, 16], [700, 540, 26], [610, 510, 20], [540, 545, 17], [760, 470, 14], [60, 200, 12], [690, 120, 10], [480, 80, 9], [330, 540, 11]];
        foreach ($blossoms as $i => [$x, $y, $r]) {
            $turn = $this->rand($i) * M_PI;
            for ($p = 0; $p < 5; $p++) {
                $angle = $turn + $p * 2 * M_PI / 5;
                $this->circle($x + cos($angle) * $r * 0.62, $y + sin($angle) * $r * 0.62, $r * 0.52, $this->color('accent', 0.42));
            }
            $this->circle($x, $y, $r * 0.22, $this->color('#f6c453', 0.9));
        }
    }

    /** Spore dust and a little toadstool above the name (mushroom). */
    private function motifToadstool(): void
    {
        for ($i = 0; $i < 90; $i++) {
            $this->circle($this->rand($i * 2) * self::WIDTH, $this->rand($i * 2 + 1) * self::HEIGHT, 1 + $this->rand($i + 300) * 2.2, $this->color('text', 0.08 + $this->rand($i + 600) * 0.1));
        }
        $y = (float) $this->style['text_y'] - 150;
        $this->roundRect(384, $y, 416, $y + 62, 10, $this->color('surface'));
        $this->roundRect(384, $y, 416, $y + 62, 10, $this->color('text', 0.12));
        imagefilledarc($this->image, $this->s(400), $this->s($y + 6), $this->s(130), $this->s(104), 180, 360, $this->color('accent'), IMG_ARC_PIE);
        $this->rect(335, $y + 2, 465, $y + 10, $this->color('accent'));
        foreach ([[375, $y - 22, 8], [412, $y - 32, 7], [440, $y - 10, 6], [355, $y - 2, 5]] as [$x, $sy, $r]) {
            $this->circle($x, $sy, $r, $this->color('#fff8ec'));
        }
        // Ground line with moss.
        $this->rect(300, $y + 62, 500, $y + 64, $this->color('accent2', 0.6));
    }

    /** Coffee beans scattered around the frame (coffee). */
    private function motifBeans(): void
    {
        $this->motifFrame();
        foreach ([[90, 100, 0.6], [140, 150, -0.4], [700, 480, 2.4], [660, 530, 0.9], [710, 110, 1.3], [95, 500, -1.1]] as [$x, $y, $angle]) {
            $this->leaf($x - cos($angle) * 22, $y - sin($angle) * 22, 46, 30, $angle, $this->color('accent', 0.85));
            $this->stroke([[$x - cos($angle) * 16, $y - sin($angle) * 16], [$x + cos($angle) * 16, $y + sin($angle) * 16]], 2.4, $this->color('accent2', 0.9));
        }
    }
}
