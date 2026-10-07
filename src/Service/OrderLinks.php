<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\Router;
use App\Http\Session;
use App\Support\Config;
use App\Support\Paths;

/**
 * Customer-facing order links. Order numbers are guessable, so pages that
 * show an order need either an HMAC key (?key=…, signed with APP_SECRET)
 * or the session that placed the order.
 */
final class OrderLinks
{
    private const SESSION_KEY = 'placed_orders';

    private ?string $secret = null;

    public function __construct(
        private readonly Config $config,
        private readonly Paths $paths,
        private readonly Router $router,
        private readonly Session $session,
    ) {
    }

    public function key(string $orderNumber): string
    {
        return substr(hash_hmac('sha256', 'order|' . $orderNumber, $this->secret()), 0, 32);
    }

    public function verify(string $orderNumber, string $key): bool
    {
        return $orderNumber !== '' && $key !== '' && hash_equals($this->key($orderNumber), $key);
    }

    /** Valid key, or the order was placed in this session. */
    public function canView(string $orderNumber, string $key): bool
    {
        return $this->verify($orderNumber, $key)
            || in_array($orderNumber, (array) $this->session->get(self::SESSION_KEY, []), true);
    }

    /** Remembers an order placed in this session (the last 10). */
    public function rememberPlaced(string $orderNumber): void
    {
        $placed = array_values(array_diff((array) $this->session->get(self::SESSION_KEY, []), [$orderNumber]));
        $placed[] = $orderNumber;
        $this->session->set(self::SESSION_KEY, array_slice($placed, -10));
    }

    /** Forgets the orders placed in this session (customer sign-out on a shared computer). */
    public function forgetPlaced(): void
    {
        $this->session->remove(self::SESSION_KEY);
    }

    /** @return array{order: string, key: string} */
    public function query(string $orderNumber): array
    {
        return ['order' => $orderNumber, 'key' => $this->key($orderNumber)];
    }

    public function confirmationPath(string $orderNumber): string
    {
        return $this->router->url('order.confirmation', [], $this->query($orderNumber));
    }

    public function confirmationUrl(string $orderNumber): string
    {
        return $this->baseUrl() . $this->confirmationPath($orderNumber);
    }

    public function trackUrl(string $orderNumber): string
    {
        return $this->baseUrl() . $this->router->url('order.track', [], $this->query($orderNumber));
    }

    public function baseUrl(): string
    {
        return rtrim($this->config->get('APP_URL', 'http://localhost'), '/');
    }

    /**
     * APP_SECRET, or else a random key generated once into var/app-secret,
     * or (if var/ isn't writable) one derived from the database password.
     */
    private function secret(): string
    {
        if ($this->secret !== null) {
            return $this->secret;
        }
        if ($this->config->has('APP_SECRET')) {
            return $this->secret = $this->config->get('APP_SECRET');
        }

        $file = $this->paths->var('app-secret');
        $stored = is_file($file) ? trim((string) file_get_contents($file)) : '';
        if (strlen($stored) >= 32) {
            return $this->secret = $stored;
        }

        // 'x' fails if another request created the file first; then use theirs.
        $handle = @fopen($file, 'x');
        if ($handle !== false) {
            $generated = bin2hex(random_bytes(32));
            fwrite($handle, $generated);
            fclose($handle);
            @chmod($file, 0600);

            return $this->secret = $generated;
        }
        $stored = is_file($file) ? trim((string) file_get_contents($file)) : '';
        if (strlen($stored) >= 32) {
            return $this->secret = $stored;
        }

        return $this->secret = hash('sha256', 'store|' . $this->config->get('DB_PASS') . '|' . $this->config->get('ADMIN_PASSWORD_HASH') . '|' . $this->config->get('DB_NAME'));
    }
}
