<?php

declare(strict_types=1);

namespace App\Repository;

use Throwable;

/**
 * Key/value settings edited in Admin → Settings (settings table).
 * Reads never throw: without a database (or before the migration has
 * run) every setting simply reads as "not set".
 */
final class SettingRepository extends Repository
{
    /** @var array<string, string>|null */
    private ?array $cache = null;

    /** @return array<string, string> */
    public function allValues(): array
    {
        if ($this->cache === null) {
            try {
                $this->cache = array_column($this->all('SELECT name, value FROM settings'), 'value', 'name');
            } catch (Throwable) {
                $this->cache = [];
            }
        }

        return $this->cache;
    }

    public function get(string $name, ?string $default = null): ?string
    {
        $value = $this->allValues()[$name] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    /** Stores a value; null deletes the setting. */
    public function set(string $name, ?string $value): void
    {
        if ($value === null) {
            $this->run('DELETE FROM settings WHERE name = :n', ['n' => $name]);
        } else {
            $this->run(
                'INSERT INTO settings (name, value) VALUES (:n, :v) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                ['n' => $name, 'v' => $value]
            );
        }
        $this->cache = null;
    }
}
