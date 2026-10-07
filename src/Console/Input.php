<?php

declare(strict_types=1);

namespace App\Console;

/** Parsed command-line arguments: positional arguments and --flags / --key=value options. */
final class Input
{
    /**
     * @param list<string>               $arguments
     * @param array<string, string|true> $options
     */
    public function __construct(
        private readonly array $arguments,
        private readonly array $options,
    ) {
    }

    /** @param list<string> $argv arguments after the command name */
    public static function fromArgv(array $argv): self
    {
        $arguments = [];
        $options = [];
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--')) {
                [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
                $options[$key] = $value;
            } else {
                $arguments[] = $arg;
            }
        }

        return new self($arguments, $options);
    }

    public function argument(int $index): ?string
    {
        return $this->arguments[$index] ?? null;
    }

    public function count(): int
    {
        return count($this->arguments);
    }

    public function hasOption(string $name): bool
    {
        return isset($this->options[$name]);
    }

    /** Value of --name=value ($default when missing or given as a bare flag). */
    public function option(string $name, ?string $default = null): ?string
    {
        $value = $this->options[$name] ?? null;

        return is_string($value) ? $value : $default;
    }
}
