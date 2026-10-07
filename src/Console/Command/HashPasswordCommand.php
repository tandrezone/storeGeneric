<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Command;
use App\Console\Input;
use App\Console\Output;

/** Prints a bcrypt hash for ADMIN_PASSWORD_HASH in .env. */
final class HashPasswordCommand implements Command
{
    public function name(): string
    {
        return 'admin:hash-password';
    }

    public function description(): string
    {
        return 'Print a bcrypt hash to put in ADMIN_PASSWORD_HASH';
    }

    public function usage(): string
    {
        return '[password]   (prompts when omitted, so it stays out of shell history)';
    }

    public function run(Input $input, Output $output): int
    {
        $password = $input->argument(0);
        if ($password === null) {
            $password = $this->prompt('Password: ');
        }
        if ($password === null || $password === '') {
            $output->error('No password given.');

            return 1;
        }

        $output->line(password_hash($password, PASSWORD_BCRYPT));

        return 0;
    }

    private function prompt(string $label): ?string
    {
        fwrite(STDERR, $label);
        $hide = stream_isatty(STDIN) && DIRECTORY_SEPARATOR === '/';
        if ($hide) {
            shell_exec('stty -echo');
        }
        $line = fgets(STDIN);
        if ($hide) {
            shell_exec('stty echo');
            fwrite(STDERR, PHP_EOL);
        }

        return $line === false ? null : rtrim($line, "\r\n");
    }
}
