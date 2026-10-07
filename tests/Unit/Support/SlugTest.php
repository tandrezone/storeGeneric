<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Slug;
use Tests\TestCase;

final class SlugTest extends TestCase
{
    public function testLowercasesAndJoinsWithDashes(): void
    {
        $this->assertSame('green-tea-250g', Slug::from('Green Tea 250g'));
        $this->assertSame('a-b-c', Slug::from('  A -- b // C  '));
    }

    public function testTransliteratesAccents(): void
    {
        $this->assertSame('creme-brulee-250g', Slug::from('Crème Brûlée 250g'));
        $this->assertSame('sao-paulo', Slug::from('São Paulo'));
    }

    public function testFallbackWhenNothingIsLeft(): void
    {
        $this->assertSame('item', Slug::from('!!!'));
        $this->assertSame('product', Slug::from('', 'product'));
    }

    public function testIsCappedAt80CharactersWithoutTrailingDash(): void
    {
        $slug = Slug::from(str_repeat('abcd ', 40));
        $this->assertTrue(strlen($slug) <= 80);
        $this->assertFalse(str_ends_with($slug, '-'));
    }
}
