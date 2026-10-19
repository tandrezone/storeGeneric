<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\Session;
use App\I18n\Translator;
use App\Repository\AdminUserRepository;
use App\Repository\LoginAttemptRepository;
use App\Support\Config;
use PDOException;
use RuntimeException;

/**
 * Session login for the admin area, backed by the admin_users table (with
 * roles), with lockout after repeated failures per client IP and per username.
 *
 * Bootstrap: while admin_users is empty, the .env account (ADMIN_USERNAME /
 * ADMIN_PASSWORD_HASH) can log in, and that first login saves it as the
 * first 'owner'. From then on only admin_users is used.
 *
 * The session holds the user id plus a fingerprint of the password hash; the
 * user is re-read on every request, so deactivating a user, or resetting
 * their password, logs them out on their next request.
 */
final class AdminAuthenticator
{
    public const MIN_PASSWORD_LENGTH = 10;
    /** bcrypt (password_hash's default) ignores everything after 72 bytes. */
    public const MAX_PASSWORD_BYTES = 72;

    private const SESSION_USER = 'admin_user_id';
    private const SESSION_FINGERPRINT = 'admin_password_fp';
    /** Password accepted, second factor (TOTP / recovery code) still to come: [user id, fingerprint, expiry time]. */
    private const SESSION_PENDING = 'admin_2fa_pending';
    private const PENDING_SECONDS = 300;
    private const MAX_ATTEMPTS = 5;
    /** Higher than per IP: someone guessing from many addresses can lock the account out for everyone. */
    private const MAX_USERNAME_ATTEMPTS = 20;
    private const LOCKOUT_MINUTES = 15;

    /** @var array<string, mixed>|null */
    private ?array $user = null;
    private bool $loaded = false;
    private bool $revoked = false;
    private ?string $lastFailure = null;
    private ?string $lastFactor = null;
    private bool $bootstrapped = false;

    public function __construct(
        private readonly Session $session,
        private readonly LoginAttemptRepository $attempts,
        private readonly AdminUserRepository $users,
        private readonly Config $config,
        private readonly Csrf $csrf,
    ) {
    }

    public function isLoggedIn(): bool
    {
        return $this->user() !== null;
    }

    /**
     * The logged-in admin (id, username, email, role, active, last_login_at,
     * created_at — never the password hash), or null. A session whose user
     * was deactivated, removed or had their password reset is ended here.
     *
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        if ($this->loaded) {
            return $this->user;
        }
        $this->loaded = true;

        $id = $this->session->get(self::SESSION_USER);
        if (!is_int($id)) {
            return null;
        }

        $row = $this->users->find($id);
        $fingerprint = (string) $this->session->get(self::SESSION_FINGERPRINT, '');
        if (
            $row === null || !(bool) $row['active']
            || !hash_equals(self::fingerprint((string) $row['password_hash']), $fingerprint)
        ) {
            $this->revoked = true;
            $this->logout();

            return null;
        }

        unset($row['password_hash'], $row['totp_secret'], $row['totp_last_step']);

        return $this->user = $row;
    }

    /** True when this request ended a session whose user may no longer be logged in. */
    public function wasRevoked(): bool
    {
        $this->user();

        return $this->revoked;
    }

    public function role(): ?AdminRole
    {
        $user = $this->user();

        return $user === null ? null : AdminRole::tryFrom((string) $user['role']);
    }

    /** Whether the logged-in admin has $role or a higher one. */
    public function can(AdminRole|string $role): bool
    {
        $required = $role instanceof AdminRole ? $role : AdminRole::from($role);

        return $this->role()?->includes($required) ?? false;
    }

    /**
     * Logs in on success. Returns false for bad credentials and for a
     * locked-out IP or username alike, so an attacker can't tell them apart
     * (lastFailure() tells the activity log which it was). The attempt is
     * recorded before the password is checked, so parallel requests can't all
     * slip in under the limit, and unknown usernames cost as much time as
     * known ones.
     */
    public function attempt(string $username, string $password, string $ip): bool
    {
        $this->lastFailure = null;
        if ($this->throttled($ip, $username)) {
            $this->lastFailure = 'locked';

            return false;
        }

        $user = $this->users->count() === 0
            ? $this->bootstrapFromEnv($username, $password)
            : $this->verify($username, $password);
        if ($user === null) {
            $this->lastFailure = 'invalid';

            return false;
        }

        // Two-factor users are not logged in yet: remember that the password
        // was right and wait for the code (completeSecondFactor()). The
        // attempts stay counted until the code is accepted. Returns false,
        // with lastFailure() 'second_factor'.
        if ((bool) $user['totp_enabled'] && (string) $user['totp_secret'] !== '') {
            $this->session->set(self::SESSION_PENDING, [
                (int) $user['id'], self::fingerprint((string) $user['password_hash']), time() + self::PENDING_SECONDS,
            ]);
            $this->lastFailure = 'second_factor';

            return false;
        }

        $this->attempts->clear($ip, $username);
        $this->startSession($user);

        return true;
    }

    /**
     * The user whose password was accepted and who must still enter a
     * two-factor code (id, username), or null.
     *
     * @return array{id: int, username: string}|null
     */
    public function pendingSecondFactor(): ?array
    {
        $pending = $this->session->get(self::SESSION_PENDING);
        if (!is_array($pending) || count($pending) !== 3 || !is_int($pending[0]) || (int) $pending[2] < time()) {
            $this->session->remove(self::SESSION_PENDING);

            return null;
        }
        $row = $this->users->find($pending[0]);
        if (
            $row === null || !(bool) $row['active'] || !(bool) $row['totp_enabled']
            || !hash_equals(self::fingerprint((string) $row['password_hash']), (string) $pending[1])
        ) {
            $this->session->remove(self::SESSION_PENDING);

            return null;
        }

        return ['id' => (int) $row['id'], 'username' => (string) $row['username']];
    }

    public function cancelSecondFactor(): void
    {
        $this->session->remove(self::SESSION_PENDING);
    }

    /**
     * Second login step: logs in when $code is a valid authenticator code or
     * an unused recovery code. Counts as a login attempt (same per IP and per
     * username limits as the password), so codes can't be guessed. On failure
     * lastFailure() is 'locked', 'invalid' or 'expired'; on success
     * lastFactor() says 'totp' or 'recovery'.
     */
    public function completeSecondFactor(string $code, string $ip): bool
    {
        $this->lastFailure = null;
        $this->lastFactor = null;
        $pending = $this->pendingSecondFactor();
        if ($pending === null) {
            $this->lastFailure = 'expired';

            return false;
        }

        $factor = $this->verifySecondFactor($pending['id'], $code, $ip);
        if ($factor === null) {
            return false;
        }

        $user = $this->users->find($pending['id']);
        if ($user === null) {
            $this->lastFailure = 'invalid';

            return false;
        }
        $this->session->remove(self::SESSION_PENDING);
        $this->attempts->clear($ip, (string) $user['username']);
        $this->startSession($user);
        $this->lastFactor = $factor;

        return true;
    }

    /**
     * Checks a code for a user outside the login (e.g. to turn 2FA off):
     * 'totp' or 'recovery' when it was valid (and is now used up), else null
     * with lastFailure() 'locked' or 'invalid'. Rate limited like logins.
     */
    public function verifySecondFactor(int $userId, string $code, string $ip): ?string
    {
        $this->lastFailure = null;
        $row = $this->users->find($userId);
        if ($row === null || !(bool) $row['totp_enabled']) {
            $this->lastFailure = 'invalid';

            return null;
        }
        if ($this->throttled($ip, (string) $row['username'])) {
            $this->lastFailure = 'locked';

            return null;
        }

        $factor = $this->checkCode($row, $code);
        if ($factor === null) {
            $this->lastFailure = 'invalid';
        }

        return $factor;
    }

    /**
     * Turns 2FA on for $userId when $code is valid for $secret (the one shown
     * during setup). Returns the new recovery codes (shown once, only their
     * hashes are kept), or null when the code is wrong.
     *
     * @return list<string>|null
     */
    public function enableTwoFactor(int $userId, string $secret, string $code): ?array
    {
        $step = Totp::verify($secret, $code, time());
        if ($step === null) {
            return null;
        }

        $codes = Totp::generateRecoveryCodes();
        $this->users->enableTotp($userId, $secret, $step, array_map(
            static fn (string $c): string => Totp::hashRecoveryCode((string) Totp::normalizeRecoveryCode($c)),
            $codes
        ));

        return $codes;
    }

    /** Turns 2FA off for $userId (secret and recovery codes are deleted). */
    public function disableTwoFactor(int $userId): void
    {
        $this->users->clearTotp($userId);
    }

    /** 'totp' or 'recovery' after a successful completeSecondFactor(). */
    public function lastFactor(): ?string
    {
        return $this->lastFactor;
    }

    /** 'locked', 'invalid', 'second_factor' (password fine, code needed) or 'expired' after a failed attempt, else null. */
    public function lastFailure(): ?string
    {
        return $this->lastFailure;
    }

    /** True when the last attempt() saved the .env account as the first owner. */
    public function wasBootstrapped(): bool
    {
        return $this->bootstrapped;
    }

    public function verifyPassword(int $userId, string $password): bool
    {
        $row = $this->users->find($userId);

        return $row !== null && password_verify($password, (string) $row['password_hash']);
    }

    /**
     * Sets a new password. Other sessions of that user end on their next
     * request; the current admin's own session stays logged in.
     */
    public function changePassword(int $userId, string $password): void
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->users->setPasswordHash($userId, $hash);

        if ($this->session->get(self::SESSION_USER) === $userId) {
            $this->session->set(self::SESSION_FINGERPRINT, self::fingerprint($hash));
        }
    }

    /** Null when $password is acceptable, else the reason (in English without a $translator). */
    public static function passwordProblem(string $password, ?Translator $translator = null): ?string
    {
        $say = static fn (string $message, array $params): string => $translator !== null
            ? $translator->trans($message, $params)
            : strtr($message, array_combine(array_map(static fn ($k) => '{' . $k . '}', array_keys($params)), array_map('strval', $params)));
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return $say('The password must be at least {min} characters long.', ['min' => self::MIN_PASSWORD_LENGTH]);
        }
        if (strlen($password) > self::MAX_PASSWORD_BYTES) {
            return $say('The password can be at most {max} bytes long.', ['max' => self::MAX_PASSWORD_BYTES]);
        }

        return null;
    }

    /**
     * Ends the admin session: new session id, admin keys removed, fresh form
     * token. Storefront data (cart, customer sign-in) in the same browser stays.
     */
    public function logout(): void
    {
        $this->session->remove(self::SESSION_USER);
        $this->session->remove(self::SESSION_FINGERPRINT);
        $this->session->remove(self::SESSION_PENDING);
        $this->session->regenerate();
        $this->csrf->regenerate();
        $this->user = null;
        $this->loaded = true;
    }

    /**
     * Records a login attempt (before anything is checked, so parallel
     * requests can't all slip in under the limit) and says whether the IP or
     * username is over its limit.
     */
    private function throttled(string $ip, string $username): bool
    {
        $this->attempts->record($ip, $username);

        return $this->attempts->countRecent($ip, self::LOCKOUT_MINUTES) > self::MAX_ATTEMPTS
            || ($username !== '' && $this->attempts->countRecentForUsername($username, self::LOCKOUT_MINUTES) > self::MAX_USERNAME_ATTEMPTS);
    }

    /**
     * Accepts an authenticator code (once per time step) or an unused
     * recovery code. $row must include the TOTP columns.
     *
     * @param array<string, mixed> $row
     */
    private function checkCode(array $row, string $code): ?string
    {
        $id = (int) $row['id'];
        $secret = (string) ($row['totp_secret'] ?? '');
        $last = $row['totp_last_step'] === null ? null : (int) $row['totp_last_step'];

        $step = $secret === '' ? null : Totp::verify($secret, $code, time(), $last);
        if ($step !== null) {
            return $this->users->claimTotpStep($id, $step) ? 'totp' : null;
        }

        $recovery = Totp::normalizeRecoveryCode($code);

        return $recovery !== null && $this->users->consumeRecoveryCode($id, Totp::hashRecoveryCode($recovery)) ? 'recovery' : null;
    }

    /** @param array<string, mixed> $user */
    private function startSession(array $user): void
    {
        $this->users->touchLastLogin((int) $user['id']);
        $this->session->regenerate();
        $this->csrf->regenerate();
        $this->session->set(self::SESSION_USER, (int) $user['id']);
        $this->session->set(self::SESSION_FINGERPRINT, self::fingerprint((string) $user['password_hash']));
        $this->loaded = false;
    }

    /** @return array<string, mixed>|null the matching active user row */
    private function verify(string $username, string $password): ?array
    {
        $user = $username === '' ? null : $this->users->findByUsername($username);
        if ($user === null) {
            // Same cost as checking a real password, so response time doesn't reveal valid usernames.
            password_hash($password, PASSWORD_DEFAULT);

            return null;
        }

        $hash = (string) $user['password_hash'];
        if (!password_verify($password, $hash) || !(bool) $user['active']) {
            return null;
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $user['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $this->users->setPasswordHash((int) $user['id'], (string) $user['password_hash']);
        }

        return $user;
    }

    /**
     * First login of a fresh install: checks the .env account and saves it
     * as the first owner.
     *
     * @return array<string, mixed>|null
     */
    private function bootstrapFromEnv(string $username, string $password): ?array
    {
        $hash = $this->config->get('ADMIN_PASSWORD_HASH');
        if ($hash === '') {
            throw new RuntimeException('No admin users exist yet and ADMIN_PASSWORD_HASH is not set.');
        }

        $valid = hash_equals($this->config->get('ADMIN_USERNAME', 'admin'), $username)
            && password_verify($password, $hash);
        if (!$valid) {
            return null;
        }

        try {
            $this->users->create($username, null, password_hash($password, PASSWORD_DEFAULT), AdminRole::Owner->value);
            $this->bootstrapped = true;
        } catch (PDOException) {
            // A parallel first login created it already.
        }

        return $this->users->findByUsername($username);
    }

    private static function fingerprint(string $passwordHash): string
    {
        return hash('sha256', 'admin-session|' . $passwordHash);
    }
}
