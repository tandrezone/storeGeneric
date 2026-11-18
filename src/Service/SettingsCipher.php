<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Encrypts secrets kept in the settings table (payment and AI keys) with
 * libsodium, using a key derived from the store secret (APP_SECRET or
 * var/app-secret) for each purpose. A database dump alone does not reveal
 * them; if the store secret changes, decrypt() returns null and the admin
 * has to enter the key again.
 */
final class SettingsCipher
{
    public const PREFIX = 'enc:v1:';

    public function __construct(private readonly OrderLinks $links)
    {
    }

    public static function isEncrypted(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }

    public function encrypt(string $plain, string $purpose): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->links->derivedKey($purpose)));
    }

    /** The plain value, or null when it can't be decrypted (other store secret, damaged value). */
    public function decrypt(string $stored, string $purpose): ?string
    {
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->links->derivedKey($purpose)
        );

        return $plain === false ? null : $plain;
    }
}
