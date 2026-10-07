<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Money;
use Tests\TestCase;

final class MoneyTest extends TestCase
{
    public function testExponents(): void
    {
        $this->assertSame(2, Money::exponent('EUR'));
        $this->assertSame(2, Money::exponent('usd'));
        $this->assertSame(0, Money::exponent('JPY'));
        $this->assertSame(3, Money::exponent('KWD'));
        $this->assertSame(2, Money::exponent('XYZ'), 'unknown currencies have 2 decimals');
    }

    public function testToAndFromMinorUnits(): void
    {
        $this->assertSame(1250, Money::toMinor(12.5, 'EUR'));
        $this->assertSame(1999, Money::toMinor(19.99, 'EUR'));
        $this->assertSame(1200, Money::toMinor(1200, 'JPY'));
        $this->assertSame(12345, Money::toMinor(12.345, 'KWD'));
        $this->assertSame(12.5, Money::fromMinor(1250, 'EUR'));
        $this->assertSame(1200.0, Money::fromMinor(1200, 'JPY'));
        $this->assertSame(12.345, Money::fromMinor(12345, 'KWD'));
    }

    public function testCoversAllowsHalfAMinorUnit(): void
    {
        $this->assertTrue(Money::covers(10.0, 10.0, 'EUR'));
        $this->assertTrue(Money::covers(9.996, 10.0, 'EUR'));
        $this->assertFalse(Money::covers(9.99, 10.0, 'EUR'));
        $this->assertTrue(Money::covers(11.0, 10.0, 'EUR'));
        $this->assertFalse(Money::covers(999.0, 1000.0, 'JPY'));
    }

    public function testFormat(): void
    {
        $this->assertSame('€12.50', Money::format(12.5, 'EUR'));
        $this->assertSame('€1,234.00', Money::format(1234, 'EUR'));
        $this->assertSame('$0.99', Money::format(0.99, 'USD'));
        $this->assertSame('¥1,200', Money::format(1200, 'JPY'));
    }

    public function testSpecForJavaScript(): void
    {
        $spec = Money::spec('EUR');
        $this->assertSame('€', $spec['symbol']);
        $this->assertTrue($spec['before']);
        $this->assertSame(2, $spec['decimals']);
        $this->assertSame(0, Money::spec('JPY')['decimals']);
    }
}
