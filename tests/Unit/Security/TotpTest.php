<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Security\Totp;
use Tests\TestCase;

final class TotpTest extends TestCase
{
    /** RFC 6238 appendix B: the ASCII secret "12345678901234567890" in base32. */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testRfc6238Vectors(): void
    {
        $vectors = [
            59          => '94287082',
            1111111109  => '07081804',
            1111111111  => '14050471',
            1234567890  => '89005924',
            2000000000  => '69279037',
            20000000000 => '65353130',
        ];
        foreach ($vectors as $time => $expected) {
            $this->assertSame($expected, Totp::code(self::RFC_SECRET, Totp::timeStep($time), 8), "time {$time}");
            $this->assertSame(substr($expected, -6), Totp::code(self::RFC_SECRET, Totp::timeStep($time)), "6 digits at {$time}");
        }
    }

    public function testBase32RoundTrip(): void
    {
        $this->assertSame('MZXW6YTBOI', Totp::base32Encode('foobar'));
        $this->assertSame('foobar', Totp::base32Decode('mzxw6ytboi======'));
        $this->assertSame('foobar', Totp::base32Decode('MZXW 6YTB OI'));
        $secret = Totp::generateSecret();
        $this->assertSame(32, strlen($secret));
        $this->assertSame(20, strlen(Totp::base32Decode($secret)));
        $this->assertNotSame($secret, Totp::generateSecret());
    }

    public function testWindowIsPlusMinusOneStep(): void
    {
        $now = 1_700_000_000;
        $step = Totp::timeStep($now);
        foreach ([-1, 0, 1] as $offset) {
            $this->assertSame($step + $offset, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + $offset), $now), "offset {$offset}");
        }
        foreach ([-2, 2] as $offset) {
            $this->assertNull(Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + $offset), $now), "offset {$offset}");
        }
    }

    public function testCodeCannotBeReused(): void
    {
        $now = 1_700_000_000;
        $step = Totp::timeStep($now);
        $code = Totp::code(self::RFC_SECRET, $step);

        $this->assertSame($step, Totp::verify(self::RFC_SECRET, $code, $now, null));
        $this->assertNull(Totp::verify(self::RFC_SECRET, $code, $now, $step), 'same step again');
        $this->assertNull(Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step - 1), $now, $step), 'older step');
        $this->assertSame($step + 1, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + 1), $now, $step), 'newer step');
    }

    public function testMalformedCodesAreRefused(): void
    {
        $now = 1_700_000_000;
        $code = Totp::code(self::RFC_SECRET, Totp::timeStep($now));
        $this->assertSame(Totp::timeStep($now), Totp::verify(self::RFC_SECRET, substr($code, 0, 3) . ' ' . substr($code, 3), $now), 'spaces are ignored');
        foreach (['', '12345', '1234567', 'abcdef', $code . 'x'] as $bad) {
            $this->assertNull(Totp::verify(self::RFC_SECRET, $bad, $now), "'{$bad}'");
        }
    }

    public function testProvisioningUri(): void
    {
        $uri = Totp::uri('ABCDEFGH', 'ana', 'My Store');
        $this->assertSame('otpauth://totp/My%20Store%3Aana?secret=ABCDEFGH&issuer=My%20Store&algorithm=SHA1&digits=6&period=30', $uri);
    }

    public function testRecoveryCodes(): void
    {
        $codes = Totp::generateRecoveryCodes();
        $this->assertSame(8, count($codes));
        $this->assertSame(8, count(array_unique($codes)));
        foreach ($codes as $code) {
            $this->assertTrue(preg_match('/^[A-Z2-7]{5}-[A-Z2-7]{5}$/', $code) === 1, $code);
            $normalized = Totp::normalizeRecoveryCode(strtolower($code));
            $this->assertSame(str_replace('-', '', $code), $normalized);
            $this->assertSame(64, strlen(Totp::hashRecoveryCode((string) $normalized)));
        }
        $this->assertNull(Totp::normalizeRecoveryCode('123456'));
        $this->assertNull(Totp::normalizeRecoveryCode('ABCDE-FGHI'));
    }
}
