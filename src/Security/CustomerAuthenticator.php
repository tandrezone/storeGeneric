<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\Session;
use App\Repository\CustomerAuthAttemptRepository;
use App\Repository\CustomerRepository;

/**
 * Storefront sign-in for customer accounts. Completely separate from the
 * admin login: its own session keys (customer_id / customer_password_fp),
 * its own attempt counters, and logging out only removes those keys (an
 * admin signed in in the same browser stays signed in).
 *
 * Like the admin login, the session holds the customer id plus a
 * fingerprint of the password hash, and the customer is re-read on every
 * request: deactivating or deleting an account, or changing / resetting its
 * password, ends its other sessions.
 */
final class CustomerAuthenticator
{
    public const MIN_PASSWORD_LENGTH = 8;
    /** bcrypt (password_hash's default) ignores everything after 72 bytes. */
    public const MAX_PASSWORD_BYTES = 72;

    public const LOCKOUT_MINUTES = 15;
    /** Per IP: higher than per email, since many customers can share one address (mobile networks, offices). */
    public const MAX_IP_ATTEMPTS = 20;
    public const MAX_EMAIL_ATTEMPTS = 5;

    private const SESSION_CUSTOMER = 'customer_id';
    private const SESSION_FINGERPRINT = 'customer_password_fp';

    /** @var array<string, mixed>|null */
    private ?array $customer = null;
    private bool $loaded = false;
    private ?string $lastFailure = null;

    public function __construct(
        private readonly Session $session,
        private readonly CustomerRepository $customers,
        private readonly CustomerAuthAttemptRepository $attempts,
        private readonly Csrf $csrf,
    ) {
    }

    /**
     * The signed-in customer (id, email, name, phone, email_verified_at, …
     * — never the password hash), or null.
     *
     * @return array<string, mixed>|null
     */
    public function customer(): ?array
    {
        if ($this->loaded) {
            return $this->customer;
        }
        $this->loaded = true;

        $id = $this->session->get(self::SESSION_CUSTOMER);
        if (!is_int($id)) {
            return null;
        }

        $row = $this->customers->find($id);
        $fingerprint = (string) $this->session->get(self::SESSION_FINGERPRINT, '');
        if (
            $row === null || !(bool) $row['active'] || $row['deleted_at'] !== null
            || !hash_equals(self::fingerprint((string) $row['password_hash']), $fingerprint)
        ) {
            $this->forget();

            return null;
        }

        unset($row['password_hash']);

        return $this->customer = $row;
    }

    public function id(): ?int
    {
        $customer = $this->customer();

        return $customer === null ? null : (int) $customer['id'];
    }

    public function isLoggedIn(): bool
    {
        return $this->customer() !== null;
    }

    /**
     * Signs in on success. Bad credentials, a deactivated account and a
     * locked-out IP or email all return false with the same message to the
     * visitor (lastFailure() says which). The attempt is recorded before the
     * password is checked, and unknown emails take as long as known ones.
     */
    public function attempt(string $email, string $password, string $ip): bool
    {
        $this->lastFailure = null;
        $email = CustomerRepository::normalizeEmail($email);
        $kind = CustomerAuthAttemptRepository::LOGIN;
        $this->attempts->record($kind, $ip, $email);
        if (
            $this->attempts->countRecentForIp($kind, $ip, self::LOCKOUT_MINUTES) > self::MAX_IP_ATTEMPTS
            || ($email !== '' && $this->attempts->countRecentForEmail($kind, $email, self::LOCKOUT_MINUTES) > self::MAX_EMAIL_ATTEMPTS)
        ) {
            $this->lastFailure = 'locked';

            return false;
        }

        $row = $email === '' ? null : $this->customers->findByEmail($email);
        if ($row === null || (string) $row['password_hash'] === '') {
            // Same cost as checking a real password, so response time doesn't reveal accounts.
            password_hash($password, PASSWORD_DEFAULT);
            $this->lastFailure = 'invalid';

            return false;
        }

        $hash = (string) $row['password_hash'];
        if (!password_verify($password, $hash) || !(bool) $row['active'] || $row['deleted_at'] !== null) {
            $this->lastFailure = 'invalid';

            return false;
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $this->customers->setPasswordHash((int) $row['id'], $hash);
            $row['password_hash'] = $hash;
        }

        $this->attempts->clearEmail($kind, $email);
        $this->login($row);

        return true;
    }

    /** 'locked' or 'invalid' after a failed attempt(), else null. */
    public function lastFailure(): ?string
    {
        return $this->lastFailure;
    }

    /**
     * Starts a customer session for $row (a customers row with its
     * password_hash): new session id and form token, nothing else in the
     * session (cart, admin login) is touched.
     *
     * @param array<string, mixed> $row
     */
    public function login(array $row): void
    {
        $this->customers->touchLastLogin((int) $row['id']);
        $this->session->regenerate();
        $this->csrf->regenerate();
        $this->session->set(self::SESSION_CUSTOMER, (int) $row['id']);
        $this->session->set(self::SESSION_FINGERPRINT, self::fingerprint((string) $row['password_hash']));
        $this->loaded = false;
    }

    /** Signs the customer out: new session id and form token; other session data stays. */
    public function logout(): void
    {
        $this->forget();
        $this->session->regenerate();
        $this->csrf->regenerate();
    }

    public function verifyPassword(int $customerId, string $password): bool
    {
        $row = $this->customers->find($customerId);

        return $row !== null && (string) $row['password_hash'] !== '' && password_verify($password, (string) $row['password_hash']);
    }

    /**
     * Sets a new password. Other sessions of that customer end on their next
     * request; the current one stays signed in (with a new session id).
     */
    public function changePassword(int $customerId, string $password): void
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->customers->setPasswordHash($customerId, $hash);

        if ($this->session->get(self::SESSION_CUSTOMER) === $customerId) {
            $this->session->regenerate();
            $this->session->set(self::SESSION_FINGERPRINT, self::fingerprint($hash));
            $this->loaded = false;
        }
    }

    /** Null when $password is acceptable, else the reason. */
    public static function passwordProblem(string $password): ?string
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return sprintf('The password must be at least %d characters long.', self::MIN_PASSWORD_LENGTH);
        }
        if (strlen($password) > self::MAX_PASSWORD_BYTES) {
            return sprintf('The password can be at most %d bytes long.', self::MAX_PASSWORD_BYTES);
        }

        return null;
    }

    private function forget(): void
    {
        $this->session->remove(self::SESSION_CUSTOMER);
        $this->session->remove(self::SESSION_FINGERPRINT);
        $this->customer = null;
        $this->loaded = true;
    }

    private static function fingerprint(string $passwordHash): string
    {
        return hash('sha256', 'customer-session|' . $passwordHash);
    }
}
