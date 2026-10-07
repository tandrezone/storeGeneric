<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Command;
use App\Console\Input;
use App\Console\Output;
use App\Repository\AdminUserRepository;
use App\Repository\LoginAttemptRepository;
use App\Security\AdminAuthenticator;
use App\Security\AdminRole;

/**
 * Recovery: creates an admin user, or resets an existing one (new password,
 * reactivated, login lockout cleared; role and email only when given).
 * Without --password a strong one is generated and printed once.
 */
final class AdminUserCommand implements Command
{
    private const USERNAME_PATTERN = '/^[A-Za-z0-9._@-]{3,60}$/';

    public function __construct(
        private readonly AdminUserRepository $users,
        private readonly LoginAttemptRepository $attempts,
    ) {
    }

    public function name(): string
    {
        return 'admin:user';
    }

    public function description(): string
    {
        return 'Create an admin user, or reset one (new password, reactivated, unlocked)';
    }

    public function usage(): string
    {
        return '<username> [--role=owner|manager|staff] [--email=address] [--password=…]   (generates a password when omitted)';
    }

    public function run(Input $input, Output $output): int
    {
        $username = trim((string) $input->argument(0));
        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            $output->error('Usage: bin/console admin:user ' . $this->usage());
            $output->error('Usernames are 3–60 characters: letters, digits and . _ @ -');

            return 1;
        }

        $role = null;
        if ($input->hasOption('role')) {
            $role = AdminRole::tryFrom((string) $input->option('role'));
            if ($role === null) {
                $output->error('--role must be one of: ' . implode(', ', AdminRole::values()) . '.');

                return 1;
            }
        }

        $email = null;
        if ($input->hasOption('email')) {
            $email = trim((string) $input->option('email'));
            if ($email !== '' && (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
                $output->error('--email is not a valid email address.');

                return 1;
            }
        }

        $password = $input->option('password');
        $generated = $password === null;
        if ($generated) {
            $password = self::generatePassword();
        }
        $problem = AdminAuthenticator::passwordProblem($password);
        if ($problem !== null) {
            $output->error($problem);

            return 1;
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $user = $this->users->findByUsername($username);
        if ($user === null) {
            $role ??= AdminRole::Owner;
            $this->users->create($username, $email === '' ? null : $email, $hash, $role->value);
            $output->line("Created {$role->value} {$username}.");
        } else {
            $id = (int) $user['id'];
            $username = (string) $user['username'];
            if ($role !== null || $email !== null) {
                $newEmail = $email ?? ($user['email'] === null ? null : (string) $user['email']);
                $newRole = $role->value ?? (string) $user['role'];
                $this->users->update($id, $newEmail === '' ? null : $newEmail, $newRole);
            }
            $this->users->setPasswordHash($id, $hash);
            $this->users->setActive($id, true);
            $output->line("Reset {$username} (" . ($role->value ?? (string) $user['role']) . '): new password, active; open sessions end on their next request.');
        }
        $this->attempts->clearUsername($username);

        if ($generated) {
            $output->line("Password: {$password}");
            $output->line('It is shown only this once — change it in Admin → My account.');
        }

        return 0;
    }

    /** 20 URL-safe characters (120 random bits). */
    private static function generatePassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(15)), '+/', '-_'), '=');
    }
}
