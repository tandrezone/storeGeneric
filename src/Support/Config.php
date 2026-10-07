<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Read-only access to configuration from the environment.
 *
 * Values come from real environment variables first, then from the
 * project's .env file (KEY=value lines, # comments, optional quotes).
 */
final class Config
{
    /** @param array<string, string> $values */
    private function __construct(private readonly array $values)
    {
    }

    public static function fromEnvironment(string $projectRoot): self
    {
        $values = [];
        $file = $projectRoot . '/.env';

        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = array_map('trim', explode('=', $line, 2));
                $quoted = strlen($value) >= 2
                    && (($value[0] === '"' && str_ends_with($value, '"'))
                        || ($value[0] === "'" && str_ends_with($value, "'")));
                $values[$key] = $quoted ? substr($value, 1, -1) : $value;
            }
        }

        foreach (getenv() as $key => $value) {
            $values[$key] = (string) $value; // real environment wins over .env
        }

        return new self($values);
    }

    /** @param array<string, string> $values */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    public function get(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? '';

        return $value === '' ? $default : $value;
    }

    public function has(string $key): bool
    {
        return ($this->values[$key] ?? '') !== '';
    }

    public function bool(string $key, bool $default = false): bool
    {
        if (!$this->has($key)) {
            return $default;
        }

        return filter_var($this->values[$key], FILTER_VALIDATE_BOOLEAN);
    }

    public function int(string $key, int $default = 0): int
    {
        return $this->has($key) ? (int) $this->values[$key] : $default;
    }
}
