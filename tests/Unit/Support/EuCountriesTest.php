<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\EuCountries;
use Tests\TestCase;

final class EuCountriesTest extends TestCase
{
    public function testNamesCodesAndAccentsAreUnderstood(): void
    {
        $this->assertSame('PT', EuCountries::code('Portugal'));
        $this->assertSame('PT', EuCountries::code(' pt '));
        $this->assertSame('ES', EuCountries::code('España'));
        $this->assertSame('ES', EuCountries::code('Espanha'));
        $this->assertSame('DE', EuCountries::code('Deutschland'));
        $this->assertSame('DE', EuCountries::code('Germany'));
        $this->assertSame('NL', EuCountries::code('Países Baixos'));
        $this->assertSame('GR', EuCountries::code('EL'), 'Greece has two codes');
        $this->assertSame('GR', EuCountries::code('Greece'));
        $this->assertSame('SE', EuCountries::code('Suécia'));
    }

    public function testNonEuAndGarbageAreNull(): void
    {
        foreach (['United Kingdom', 'GB', 'USA', 'Switzerland', 'XI', '', '  ', 'Atlantis'] as $country) {
            $this->assertNull(EuCountries::code($country), $country);
        }
    }

    public function testVatPrefixes(): void
    {
        $this->assertSame('EL', EuCountries::vatPrefix('GR'));
        $this->assertSame('PT', EuCountries::vatPrefix('PT'));
        $this->assertSame('GR', EuCountries::fromVatPrefix('EL'));
    }
}
