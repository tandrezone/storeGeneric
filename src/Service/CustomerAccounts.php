<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\Router;
use App\I18n\Translator;
use App\Repository\CustomerAddressRepository;
use App\Repository\CustomerAuthAttemptRepository;
use App\Repository\CustomerRepository;
use App\Repository\CustomerTokenRepository;
use App\Repository\OrderRepository;
use App\Security\CustomerAuthenticator;
use PDOException;
use RuntimeException;

/**
 * Customer account rules: registration, email verification, password reset,
 * profile / password / email changes, account deletion and address input.
 * Methods throw RuntimeException with a message safe to show the customer.
 *
 * Tokens (reset: 1 hour, verify: 7 days) are random 256-bit values sent by
 * email; only their SHA-256 is stored and each works once. Guest orders
 * placed with an email are linked to the account only once its owner has
 * proven they own that address (verify link or password reset).
 */
final class CustomerAccounts
{
    public const RESET_MINUTES = 60;
    public const VERIFY_MINUTES = 7 * 24 * 60;

    public const RESET_WINDOW_MINUTES = 60;
    public const RESET_MAX_PER_IP = 10;
    public const RESET_MAX_PER_EMAIL = 3;
    public const REGISTER_WINDOW_MINUTES = 60;
    public const REGISTER_MAX_PER_IP = 10;

    /** Column sizes of customers / customer_addresses. */
    private const MAX_LENGTHS = [
        'name' => 150, 'email' => 190, 'phone' => 40, 'label' => 60, 'address1' => 190, 'address2' => 190,
        'city' => 100, 'state' => 100, 'postal_code' => 20, 'country' => 100,
    ];
    private const LABELS = [
        'name' => 'Full name', 'email' => 'Email', 'phone' => 'Phone', 'label' => 'Label', 'address1' => 'Address',
        'address2' => 'Address line 2', 'city' => 'City', 'state' => 'State / Province', 'postal_code' => 'Postal code', 'country' => 'Country',
    ];

    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly CustomerTokenRepository $tokens,
        private readonly CustomerAuthAttemptRepository $attempts,
        private readonly OrderRepository $orders,
        private readonly CustomerAuthenticator $auth,
        private readonly CustomerNotifier $notifier,
        private readonly OrderLinks $links,
        private readonly Router $router,
        private readonly Translator $translator,
    ) {
    }

    /**
     * Creates an account and emails the verification link (signing in
     * doesn't wait for it).
     *
     * @param array<string, mixed> $input name, email, password, password_confirm
     * @return array<string, mixed> the new customers row (with its password hash, for CustomerAuthenticator::login())
     */
    public function register(array $input, string $ip): array
    {
        $name = $this->text($input, 'name', true);
        $email = $this->email($input);
        $password = (string) ($input['password'] ?? '');
        $this->checkNewPassword($password, (string) ($input['password_confirm'] ?? ''));

        $kind = CustomerAuthAttemptRepository::REGISTER;
        if ($this->attempts->countRecentForIp($kind, $ip, self::REGISTER_WINDOW_MINUTES) >= self::REGISTER_MAX_PER_IP) {
            throw new RuntimeException($this->translator->trans('Too many new accounts from your network. Please try again later.'));
        }
        $this->attempts->record($kind, $ip, $email);

        if ($this->customers->findByEmail($email) !== null) {
            throw new RuntimeException($this->translator->trans('An account with this email already exists. Sign in, or reset your password if you forgot it.'));
        }
        try {
            $id = $this->customers->create($email, $name, password_hash($password, PASSWORD_DEFAULT), null, $this->translator->locale());
        } catch (PDOException) {
            throw new RuntimeException($this->translator->trans('An account with this email already exists. Sign in, or reset your password if you forgot it.'));
        }

        $customer = $this->customers->find($id) ?? throw new RuntimeException($this->translator->trans('Could not create the account.'));
        $this->sendVerification($customer, true);

        return $customer;
    }

    /**
     * (Re)sends the verification link for the account's email; older links stop working.
     *
     * @param array<string, mixed> $customer customers row
     */
    public function sendVerification(array $customer, bool $welcome = false): bool
    {
        $token = $this->newToken((int) $customer['id'], CustomerTokenRepository::VERIFY, (string) $customer['email'], self::VERIFY_MINUTES);
        $url = $this->links->baseUrl() . $this->router->url('account.verify', [], ['token' => $token]);

        return $this->notifier->verifyEmail((string) $customer['email'], (string) $customer['name'], $url, $welcome);
    }

    /**
     * Verify link: marks the email verified and links the guest orders placed
     * with it. Null when the link is invalid, expired, used, or for an
     * address the account no longer has.
     *
     * @return array{customer_id: int, linked: int}|null
     */
    public function verifyEmail(string $token): ?array
    {
        $row = $this->validToken($token, CustomerTokenRepository::VERIFY);
        if ($row === null || !$this->tokens->consume((int) $row['id'])) {
            return null;
        }
        $customerId = (int) $row['customer_id'];
        if (!$this->customers->markVerified($customerId, (string) $row['email'])) {
            return null;
        }

        return ['customer_id' => $customerId, 'linked' => $this->orders->linkGuestOrders($customerId, (string) $row['email'])];
    }

    /**
     * "Forgot password": emails a reset link if an active account has that
     * email. The answer to the visitor is the same either way; false only
     * when too many requests came from this IP or for this email.
     */
    public function requestPasswordReset(string $email, string $ip): bool
    {
        $email = CustomerRepository::normalizeEmail($email);
        $kind = CustomerAuthAttemptRepository::RESET;
        if (
            $this->attempts->countRecentForIp($kind, $ip, self::RESET_WINDOW_MINUTES) >= self::RESET_MAX_PER_IP
            || $this->attempts->countRecentForEmail($kind, $email, self::RESET_WINDOW_MINUTES) >= self::RESET_MAX_PER_EMAIL
        ) {
            return false;
        }
        $this->attempts->record($kind, $ip, $email);

        $customer = filter_var($email, FILTER_VALIDATE_EMAIL) ? $this->customers->findByEmail($email) : null;
        if ($customer === null || !(bool) $customer['active'] || $customer['deleted_at'] !== null) {
            return true;
        }

        $this->tokens->invalidateAll((int) $customer['id'], CustomerTokenRepository::RESET);
        $token = $this->newToken((int) $customer['id'], CustomerTokenRepository::RESET, $email, self::RESET_MINUTES);
        $url = $this->links->baseUrl() . $this->router->url('account.reset', [], ['token' => $token]);
        $this->notifier->passwordReset($email, (string) $customer['name'], $url, self::RESET_MINUTES);

        return true;
    }

    /** Whether a reset link can still be used (for showing the form). */
    public function isResetTokenValid(string $token): bool
    {
        return $this->validToken($token, CustomerTokenRepository::RESET) !== null;
    }

    /**
     * Sets a new password from a reset link (single use, 1 hour). The link
     * came by email, so it also verifies that address.
     *
     * @param array<string, mixed> $input password, password_confirm
     * @return array<string, mixed> the customers row (with its new hash)
     */
    public function resetPassword(string $token, array $input): array
    {
        $password = (string) ($input['password'] ?? '');
        $this->checkNewPassword($password, (string) ($input['password_confirm'] ?? ''));

        $row = $this->validToken($token, CustomerTokenRepository::RESET);
        if ($row === null || !$this->tokens->consume((int) $row['id'])) {
            throw new RuntimeException($this->translator->trans('This reset link is invalid or has expired. Please request a new one.'));
        }
        $customerId = (int) $row['customer_id'];
        $customer = $this->customers->find($customerId);
        if ($customer === null || !(bool) $customer['active'] || $customer['deleted_at'] !== null) {
            throw new RuntimeException($this->translator->trans('This reset link is invalid or has expired. Please request a new one.'));
        }

        $this->customers->setPasswordHash($customerId, password_hash($password, PASSWORD_DEFAULT));
        $this->tokens->invalidateAll($customerId, CustomerTokenRepository::RESET);
        $this->attempts->clearEmail(CustomerAuthAttemptRepository::LOGIN, (string) $customer['email']);
        if ($this->customers->markVerified($customerId, (string) $row['email'])) {
            $this->orders->linkGuestOrders($customerId, (string) $row['email']);
        }

        return $this->customers->find($customerId) ?? $customer;
    }

    /** @param array<string, mixed> $input name, phone */
    public function updateProfile(int $customerId, array $input): void
    {
        $this->customers->updateProfile($customerId, $this->text($input, 'name', true), $this->text($input, 'phone') ?: null);
    }

    /** The account's language (a code from Translator::normalize()), used for emails and when signing in. */
    public function setLanguage(int $customerId, string $locale): void
    {
        $this->customers->setLocale($customerId, $locale);
    }

    /**
     * New email address (needs the current password). It must be verified
     * again; a link is sent to the new address.
     *
     * @param array<string, mixed> $input email, current_password
     */
    public function changeEmail(int $customerId, array $input): bool
    {
        $email = $this->email($input);
        $customer = $this->customers->find($customerId) ?? throw new RuntimeException($this->translator->trans('Account not found.'));
        if ($email === $customer['email']) {
            return false;
        }
        if (!$this->auth->verifyPassword($customerId, (string) ($input['current_password'] ?? ''))) {
            throw new RuntimeException($this->translator->trans('Your current password is not correct.'));
        }
        if ($this->customers->findByEmail($email) !== null) {
            throw new RuntimeException($this->translator->trans('Another account already uses that email.'));
        }
        try {
            $this->customers->changeEmail($customerId, $email);
        } catch (PDOException) {
            throw new RuntimeException($this->translator->trans('Another account already uses that email.'));
        }
        $this->tokens->invalidateAll($customerId, CustomerTokenRepository::VERIFY);
        $this->sendVerification(['email' => $email] + $customer);

        return true;
    }

    /** @param array<string, mixed> $input current_password, password, password_confirm */
    public function changePassword(int $customerId, array $input): void
    {
        if (!$this->auth->verifyPassword($customerId, (string) ($input['current_password'] ?? ''))) {
            throw new RuntimeException($this->translator->trans('Your current password is not correct.'));
        }
        $password = (string) ($input['password'] ?? '');
        $this->checkNewPassword($password, (string) ($input['password_confirm'] ?? ''));
        $this->auth->changePassword($customerId, $password);
        $this->tokens->invalidateAll($customerId, CustomerTokenRepository::RESET);
    }

    /** Deletes (anonymises) the account; its orders are kept. Needs the current password. */
    public function deleteAccount(int $customerId, string $password): void
    {
        if (!$this->auth->verifyPassword($customerId, $password)) {
            throw new RuntimeException($this->translator->trans('Your current password is not correct.'));
        }
        $this->customers->anonymise($customerId);
    }

    /**
     * A checked address from a form (saved-address form or checkout).
     *
     * @param array<string, mixed> $input
     * @return array<string, string> CustomerAddressRepository::FIELDS
     */
    public function addressFromInput(array $input): array
    {
        $address = [];
        foreach (CustomerAddressRepository::FIELDS as $field) {
            $address[$field] = $this->text($input, $field, in_array($field, ['name', 'address1', 'city', 'postal_code', 'country'], true));
        }

        return $address;
    }

    /** Throws when the new password is too short / long or the confirmation differs. */
    public function checkNewPassword(string $password, string $confirm): void
    {
        if (mb_strlen($password) < CustomerAuthenticator::MIN_PASSWORD_LENGTH) {
            throw new RuntimeException($this->translator->trans('The password must be at least {min} characters long.', ['min' => CustomerAuthenticator::MIN_PASSWORD_LENGTH]));
        }
        if (CustomerAuthenticator::passwordProblem($password) !== null) {
            throw new RuntimeException($this->translator->trans('The password can be at most {max} bytes long.', ['max' => CustomerAuthenticator::MAX_PASSWORD_BYTES]));
        }
        if (!hash_equals($password, $confirm)) {
            throw new RuntimeException($this->translator->trans('The passwords don\'t match.'));
        }
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function newToken(int $customerId, string $purpose, string $email, int $minutes): string
    {
        if ($purpose === CustomerTokenRepository::VERIFY) {
            $this->tokens->invalidateAll($customerId, $purpose);
        }
        $token = bin2hex(random_bytes(32));
        $this->tokens->create($customerId, $purpose, self::hashToken($token), CustomerRepository::normalizeEmail($email), $minutes);

        return $token;
    }

    /** @return array<string, mixed>|null */
    private function validToken(string $token, string $purpose): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        return $this->tokens->findValid(self::hashToken($token), $purpose);
    }

    /** @param array<string, mixed> $input */
    private function email(array $input): string
    {
        $email = CustomerRepository::normalizeEmail((string) ($input['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > self::MAX_LENGTHS['email']) {
            throw new RuntimeException($this->translator->trans('Please enter a valid email address.'));
        }

        return $email;
    }

    /** @param array<string, mixed> $input */
    private function text(array $input, string $field, bool $required = false): string
    {
        $value = trim((string) ($input[$field] ?? ''));
        $label = $this->translator->trans(self::LABELS[$field] ?? $field);
        if ($required && $value === '') {
            throw new RuntimeException($this->translator->trans('{field} is required.', ['field' => $label]));
        }
        $max = self::MAX_LENGTHS[$field] ?? 190;
        if (mb_strlen($value) > $max) {
            throw new RuntimeException($this->translator->trans('{field} can be at most {max} characters.', ['field' => $label, 'max' => $max]));
        }

        return $value;
    }
}
