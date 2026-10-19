<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Time-based one-time passwords (RFC 6238, HMAC-SHA1, 30 second steps) and
 * the base32 (RFC 4648) encoding authenticator apps use for the shared
 * secret. No dependencies: just hash_hmac().
 */
final class Totp
{
    public const STEP_SECONDS = 30;
    public const DIGITS = 6;
    /** Steps accepted before / after the current one (clock drift). */
    public const WINDOW = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new random secret: 20 bytes (160 bits), base32, 32 characters. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function timeStep(int $time): int
    {
        return intdiv($time, self::STEP_SECONDS);
    }

    /** The code for a time step (RFC 4226 HOTP with the step as counter). */
    public static function code(string $secret, int $step, int $digits = self::DIGITS): string
    {
        $key = self::base32Decode($secret);
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * The time step $code is valid for (within the window around $time), or
     * null. A step at or before $lastStep is refused, so a code can't be
     * used twice. Every candidate is compared in constant time.
     */
    public static function verify(string $secret, string $code, int $time, ?int $lastStep = null): ?int
    {
        $code = preg_replace('/[\s-]+/', '', $code) ?? '';
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }

        $current = self::timeStep($time);
        $matched = null;
        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $step = $current + $offset;
            if ($step < 0) {
                continue;
            }
            if (hash_equals(self::code($secret, $step), $code) && ($lastStep === null || $step > $lastStep)) {
                $matched = $step;
            }
        }

        return $matched;
    }

    /** otpauth:// URI for authenticator apps (shown as text; no QR image is generated). */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::STEP_SECONDS;
    }

    public static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    /** Decodes base32 (case-insensitive, spaces and padding ignored). Invalid characters are skipped. */
    public static function base32Decode(string $text): string
    {
        $text = strtoupper(rtrim(preg_replace('/\s+/', '', $text) ?? '', '='));
        $bits = '';
        foreach (str_split($text) as $char) {
            $value = strpos(self::ALPHABET, $char);
            if ($value !== false) {
                $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
            }
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }

    /**
     * One-time recovery codes like "ABCDE-FGHIJ" (50 random bits each).
     *
     * @return list<string>
     */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = self::base32Encode(random_bytes(7)); // 56 bits -> 12 chars
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
        }

        return $codes;
    }

    /** Upper-case, without separators; null when it can't be a recovery code. */
    public static function normalizeRecoveryCode(string $code): ?string
    {
        $code = strtoupper(preg_replace('/[\s-]+/', '', $code) ?? '');

        return preg_match('/^[A-Z2-7]{10}$/', $code) === 1 ? $code : null;
    }

    public static function hashRecoveryCode(string $normalized): string
    {
        return hash('sha256', 'admin-recovery|' . $normalized);
    }
}
