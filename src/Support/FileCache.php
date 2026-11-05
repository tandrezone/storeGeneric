<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A small file cache for storefront data (JSON, under var/cache/data/): entries expire after their TTL
 * and ALL of them are dropped at once by bump(), which the admin and checkout call when something
 * changes. Only data is cached — never HTML, which carries each visitor's CSRF token and cart.
 *
 * CACHE_ENABLED=false (or APP_DEBUG=true) turns it off; then remember() just calls its callback.
 */
final class FileCache
{
    private const SWEEP_AFTER = 86400;

    public function __construct(private readonly Paths $paths, private readonly Config $config)
    {
    }

    public function enabled(): bool
    {
        return $this->config->bool('CACHE_ENABLED', true) && !$this->config->bool('APP_DEBUG');
    }

    /**
     * @template T
     * @param callable(): T $compute
     * @return T the cached value, or $compute() (stored for $ttl seconds)
     */
    public function remember(string $key, int $ttl, callable $compute): mixed
    {
        if (!$this->enabled()) {
            return $compute();
        }
        $file = $this->file($key);
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $entry = json_decode($raw, true);
            if (is_array($entry) && ($entry['expires'] ?? 0) > time() && array_key_exists('value', $entry)) {
                return $entry['value'];
            }
        }

        $value = $compute();
        $this->write($file, ['expires' => time() + max(1, $ttl), 'value' => $value]);

        return $value;
    }

    /** Invalidates every entry (a new version number: old files are never read again and are swept later). */
    public function bump(): void
    {
        $dir = $this->dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        @file_put_contents($dir . '/_version', (string) (microtime(true) * 1000000), LOCK_EX);
    }

    private function version(): string
    {
        $raw = @file_get_contents($this->dir() . '/_version');

        return $raw === false ? '0' : trim($raw);
    }

    private function file(string $key): string
    {
        return $this->dir() . '/' . sha1($this->version() . '|' . $key) . '.json';
    }

    private function dir(): string
    {
        return $this->paths->var('cache/data');
    }

    /** @param array<string, mixed> $entry */
    private function write(string $file, array $entry): void
    {
        $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $dir = dirname($file);
        if ($json === false || (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir))) {
            return; // a cache that can't write is just a slower site
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $json) !== false) {
            @rename($tmp, $file);
        } else {
            @unlink($tmp);
        }
        if (random_int(1, 100) === 1) {
            $this->sweep();
        }
    }

    /** Deletes entries nobody has needed for a day (old versions, expired keys). */
    private function sweep(): void
    {
        foreach (glob($this->dir() . '/*.{json,tmp}', GLOB_BRACE) ?: [] as $file) {
            if (@filemtime($file) < time() - self::SWEEP_AFTER) {
                @unlink($file);
            }
        }
    }
}
