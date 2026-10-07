<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Command;
use App\Console\Input;
use App\Console\Output;
use App\Repository\LoginAttemptRepository;

/** Clears the failed-login lockout of an admin username (and optionally of a client IP). */
final class AdminUnlockCommand implements Command
{
    public function __construct(private readonly LoginAttemptRepository $attempts)
    {
    }

    public function name(): string
    {
        return 'admin:unlock';
    }

    public function description(): string
    {
        return 'Clear the login lockout of an admin username (and --ip=address)';
    }

    public function usage(): string
    {
        return '<username> [--ip=address]   (or just --ip=address)';
    }

    public function run(Input $input, Output $output): int
    {
        $username = trim((string) $input->argument(0));
        $ip = trim((string) $input->option('ip', ''));
        if ($username === '' && $ip === '') {
            $output->error('Usage: bin/console admin:unlock ' . $this->usage());

            return 1;
        }

        if ($username !== '') {
            $count = $this->attempts->clearUsername($username);
            $output->line("Cleared {$count} login attempt(s) for username {$username}.");
        }
        if ($ip !== '') {
            $count = $this->attempts->clearIp($ip);
            $output->line("Cleared {$count} login attempt(s) from {$ip}.");
        }

        return 0;
    }
}
