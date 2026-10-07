<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Thin wrapper around the native PHP session, started by SessionMiddleware.
 * Everything that needs session state goes through this class.
 */
final class Session
{
    private const FLASH_KEY = '_flash';

    public function start(bool $secure): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
            return;
        }

        // Reject session ids the server never issued (session fixation) and
        // only ever take the id from the cookie.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public function id(): string
    {
        return session_id() ?: 'cli';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** New session id (call after login to prevent session fixation). */
    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /** Removes all session data but keeps the session running (e.g. for a flash message). */
    public function clear(): void
    {
        $_SESSION = [];
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /** Queue a one-time message for the next page ("success" or "error"). */
    public function flash(string $type, string $message): void
    {
        $_SESSION[self::FLASH_KEY][] = ['type' => $type, 'message' => $message];
    }

    /** Number of queued (not yet shown) messages of $type, without removing them. */
    public function countFlashes(string $type): int
    {
        $messages = $_SESSION[self::FLASH_KEY] ?? [];

        return count(array_filter($messages, static fn (array $m) => ($m['type'] ?? '') === $type));
    }

    /** @return list<array{type: string, message: string}> messages, removed once read */
    public function takeFlashes(): array
    {
        $messages = $_SESSION[self::FLASH_KEY] ?? [];
        unset($_SESSION[self::FLASH_KEY]);

        return $messages;
    }
}
