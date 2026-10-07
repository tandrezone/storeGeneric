<?php

declare(strict_types=1);

namespace App\Console;

/** Writes to STDOUT / STDERR (or any streams, for tests). */
final class Output
{
    /**
     * @param resource $out
     * @param resource $err
     */
    public function __construct(private $out = STDOUT, private $err = STDERR)
    {
    }

    public function line(string $text = ''): void
    {
        fwrite($this->out, $text . PHP_EOL);
    }

    public function error(string $text): void
    {
        fwrite($this->err, $text . PHP_EOL);
    }
}
