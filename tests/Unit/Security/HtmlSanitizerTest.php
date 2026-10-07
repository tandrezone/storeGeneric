<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Security\HtmlSanitizer;
use Tests\TestCase;

final class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    public function setUp(): void
    {
        $this->sanitizer = new HtmlSanitizer();
    }

    public function testKeepsAllowedFormatting(): void
    {
        $html = '<p><strong>Bold</strong> and <em>italic</em></p><ul><li>One</li></ul>';
        $this->assertSame($html, $this->sanitizer->clean($html));
    }

    public function testRemovesScriptsAndStylesWithTheirContent(): void
    {
        $clean = $this->sanitizer->clean('<p>Hi</p><script>alert(1)</script><style>p{}</style>');
        $this->assertSame('<p>Hi</p>', $clean);
    }

    public function testStripsAttributesAndEventHandlers(): void
    {
        $clean = $this->sanitizer->clean('<p onclick="x()" style="color:red" class="a">Text</p>');
        $this->assertSame('<p>Text</p>', $clean);
    }

    public function testUnwrapsUnknownTagsButKeepsTheirText(): void
    {
        $this->assertSame('<p>Hello world</p>', $this->sanitizer->clean('<p>Hello <span>world</span></p>'));
        $this->assertStringNotContainsString('<img', $this->sanitizer->clean('<p><img src=x onerror=alert(1)>ok</p>'));
    }

    public function testLinksKeepOnlySafeUrls(): void
    {
        $safe = $this->sanitizer->clean('<a href="https://example.com" onclick="x()">x</a>');
        $this->assertStringContainsString('href="https://example.com"', $safe);
        $this->assertStringContainsString('rel="noopener noreferrer"', $safe);
        $this->assertStringNotContainsString('onclick', $safe);

        $unsafe = $this->sanitizer->clean('<a href="javascript:alert(1)">x</a>');
        $this->assertStringNotContainsString('javascript', $unsafe);
    }

    public function testDivBecomesParagraph(): void
    {
        $this->assertSame('<p>One</p><p>Two</p>', $this->sanitizer->clean('<div>One</div><div>Two</div>'));
    }

    public function testEmptyAndPlainText(): void
    {
        $this->assertSame('', $this->sanitizer->clean('   '));
        $this->assertSame('5 &lt; 6 &amp; 7', $this->sanitizer->clean('5 < 6 & 7'));
    }
}
