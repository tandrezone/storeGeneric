<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controller\Shop\AccountController;
use App\Http\Exception\HttpException;
use App\Repository\CustomerAddressRepository;
use App\Repository\CustomerRepository;
use App\Repository\CustomerTokenRepository;
use App\Repository\OrderRepository;
use App\Security\CustomerAuthenticator;
use App\Service\CheckoutService;
use App\Service\CustomerAccounts;
use Nyholm\Psr7\ServerRequest;
use RuntimeException;
use Tests\IntegrationTestCase;

final class CustomerAccountsTest extends IntegrationTestCase
{
    private const PASSWORD = 'correct horse battery';

    public function setUp(): void
    {
        parent::setUp();
        $this->get(CustomerAuthenticator::class)->logout();
    }

    public function tearDown(): void
    {
        $this->get(CustomerAuthenticator::class)->logout();
    }

    public function testRegistrationCreatesAccountWithVerifyToken(): void
    {
        $email = $this->email();
        $customer = $this->register(strtoupper($email));

        $this->assertSame($email, $customer['email'], 'stored lower-case');
        $this->assertTrue(password_verify(self::PASSWORD, (string) $customer['password_hash']));
        $this->assertNull($customer['email_verified_at'], 'not verified yet');
        $this->assertSame(1, $this->tokenCount((int) $customer['id'], 'verify'), 'verification link created');

        $accounts = $this->get(CustomerAccounts::class);
        $this->assertThrows(RuntimeException::class, fn () => $this->register($email), 'already exists');
        $this->assertThrows(RuntimeException::class, fn () => $accounts->register(
            ['name' => 'X', 'email' => $this->email(), 'password' => 'short', 'password_confirm' => 'short'],
            $this->ip()
        ), 'at least 8');
        $this->assertThrows(RuntimeException::class, fn () => $accounts->register(
            ['name' => 'X', 'email' => $this->email(), 'password' => self::PASSWORD, 'password_confirm' => self::PASSWORD . '!'],
            $this->ip()
        ), 'match');
        $this->assertThrows(RuntimeException::class, fn () => $accounts->register(
            ['name' => '', 'email' => $this->email(), 'password' => self::PASSWORD, 'password_confirm' => self::PASSWORD],
            $this->ip()
        ), 'Full name');
    }

    public function testLoginAndLockoutPerEmail(): void
    {
        $customer = $this->register();
        $auth = $this->get(CustomerAuthenticator::class);

        $this->assertTrue($auth->attempt((string) $customer['email'], self::PASSWORD, $this->ip()));
        $this->assertSame((int) $customer['id'], $auth->id());
        $auth->logout();
        $this->assertNull($auth->customer());

        for ($i = 0; $i < CustomerAuthenticator::MAX_EMAIL_ATTEMPTS; $i++) {
            $this->assertFalse($auth->attempt((string) $customer['email'], 'wrong password', $this->ip()));
            $this->assertSame('invalid', $auth->lastFailure());
        }
        $this->assertFalse($auth->attempt((string) $customer['email'], self::PASSWORD, $this->ip()), 'right password, but locked');
        $this->assertSame('locked', $auth->lastFailure());
        $this->assertNull($auth->customer());

        // Unknown emails fail the same way; a deactivated account can't sign in.
        $this->assertFalse($auth->attempt($this->email(), self::PASSWORD, $this->ip()));
        $other = $this->register();
        $this->get(CustomerRepository::class)->setActive((int) $other['id'], false);
        $this->assertFalse($auth->attempt((string) $other['email'], self::PASSWORD, $this->ip()));
    }

    public function testLockoutPerIp(): void
    {
        $customer = $this->register();
        $auth = $this->get(CustomerAuthenticator::class);
        $ip = $this->ip();
        for ($i = 0; $i < CustomerAuthenticator::MAX_IP_ATTEMPTS; $i++) {
            $auth->attempt($this->email(), 'guess', $ip);
        }
        $this->assertFalse($auth->attempt((string) $customer['email'], self::PASSWORD, $ip));
        $this->assertSame('locked', $auth->lastFailure());
        $this->assertTrue($auth->attempt((string) $customer['email'], self::PASSWORD, $this->ip()), 'other IPs are not affected');
    }

    public function testSessionEndsWhenDeactivatedOrPasswordChangedElsewhere(): void
    {
        $customer = $this->register();
        $auth = $this->get(CustomerAuthenticator::class);
        $repo = $this->get(CustomerRepository::class);

        $auth->login($customer);
        $this->assertNotNull($auth->customer());

        $repo->setPasswordHash((int) $customer['id'], password_hash('another password', PASSWORD_DEFAULT));
        $this->reloadAuth();
        $this->assertNull($auth->customer(), 'password reset elsewhere ends the session');

        $auth->login((array) $repo->find((int) $customer['id']));
        $this->assertNotNull($auth->customer());
        $repo->setActive((int) $customer['id'], false);
        $this->reloadAuth();
        $this->assertNull($auth->customer(), 'deactivated by an admin');
    }

    public function testResetTokenIsSingleUseAndExpires(): void
    {
        $customer = $this->register();
        $id = (int) $customer['id'];
        $accounts = $this->get(CustomerAccounts::class);
        $tokens = $this->get(CustomerTokenRepository::class);
        $new = ['password' => 'brand new password', 'password_confirm' => 'brand new password'];

        $token = bin2hex(random_bytes(32));
        $tokens->create($id, 'reset', CustomerAccounts::hashToken($token), (string) $customer['email'], CustomerAccounts::RESET_MINUTES);
        $this->assertTrue($accounts->isResetTokenValid($token));

        $updated = $accounts->resetPassword($token, $new);
        $this->assertTrue(password_verify('brand new password', (string) $updated['password_hash']));
        $this->assertNotNull($updated['email_verified_at'], 'the emailed link proves the address');
        $this->assertFalse($accounts->isResetTokenValid($token));
        $this->assertThrows(RuntimeException::class, fn () => $accounts->resetPassword($token, $new), 'invalid or has expired');

        $expired = bin2hex(random_bytes(32));
        $tokens->create($id, 'reset', CustomerAccounts::hashToken($expired), (string) $customer['email'], CustomerAccounts::RESET_MINUTES);
        $this->pdo()->prepare('UPDATE customer_tokens SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE token_hash = ?')
            ->execute([CustomerAccounts::hashToken($expired)]);
        $this->assertFalse($accounts->isResetTokenValid($expired));
        $this->assertThrows(RuntimeException::class, fn () => $accounts->resetPassword($expired, $new), 'invalid or has expired');

        $this->assertThrows(RuntimeException::class, fn () => $accounts->resetPassword('not-a-token', $new), 'invalid');
    }

    public function testResetRequestsAreThrottledAndDontRevealAccounts(): void
    {
        $customer = $this->register();
        $accounts = $this->get(CustomerAccounts::class);

        $this->assertTrue($accounts->requestPasswordReset($this->email(), $this->ip()), 'unknown email: same answer');
        $this->assertTrue($accounts->requestPasswordReset((string) $customer['email'], $this->ip()));
        $this->assertSame(1, $this->tokenCount((int) $customer['id'], 'reset', true));
        $this->assertTrue($accounts->requestPasswordReset((string) $customer['email'], $this->ip()));
        $this->assertSame(1, $this->tokenCount((int) $customer['id'], 'reset', true), 'a new link replaces the old one');

        for ($i = 2; $i < CustomerAccounts::RESET_MAX_PER_EMAIL; $i++) {
            $accounts->requestPasswordReset((string) $customer['email'], $this->ip());
        }
        $this->assertFalse($accounts->requestPasswordReset((string) $customer['email'], $this->ip()), 'per-email limit');
    }

    public function testVerificationLinksGuestOrdersButCheckoutNeverAutoLinks(): void
    {
        $email = $this->email();
        $guestOrder = $this->orderFor($email, null);
        $customer = $this->register($email);
        $laterGuestOrder = $this->orderFor($email, null);

        $this->assertNull($this->orderRow($guestOrder['id'])['customer_id'], 'registering alone links nothing');
        $this->assertNull($this->orderRow($laterGuestOrder['id'])['customer_id'], 'guest checkout with an account email is not linked');

        $token = bin2hex(random_bytes(32));
        $this->get(CustomerTokenRepository::class)->create((int) $customer['id'], 'verify', CustomerAccounts::hashToken($token), $email, 60);
        $result = $this->get(CustomerAccounts::class)->verifyEmail($token);
        $this->assertSame(['customer_id' => (int) $customer['id'], 'linked' => 2], $result);
        $this->assertEquals($customer['id'], $this->orderRow($guestOrder['id'])['customer_id']);
        $this->assertNull($this->get(CustomerAccounts::class)->verifyEmail($token), 'single use');

        // A verify link for an address the account no longer has does nothing.
        $other = $this->register();
        $stale = bin2hex(random_bytes(32));
        $this->get(CustomerTokenRepository::class)->create((int) $other['id'], 'verify', CustomerAccounts::hashToken($stale), 'old-' . $other['email'], 60);
        $this->assertNull($this->get(CustomerAccounts::class)->verifyEmail($stale));
    }

    public function testOrdersAreOnlyVisibleToTheirOwner(): void
    {
        $alice = $this->register();
        $bob = $this->register();
        $order = $this->orderFor((string) $alice['email'], (int) $alice['id']);
        $orders = $this->get(OrderRepository::class);

        $this->assertEquals($alice['id'], $this->orderRow($order['id'])['customer_id']);
        $this->assertNotNull($orders->findByNumberForCustomer($order['order_number'], (int) $alice['id']));
        $this->assertNull($orders->findByNumberForCustomer($order['order_number'], (int) $bob['id']));
        $this->assertSame(1, $orders->countForCustomer((int) $alice['id']));
        $this->assertSame(0, $orders->countForCustomer((int) $bob['id']));

        $controller = $this->get(AccountController::class);
        $request = new ServerRequest('GET', '/account/orders/' . $order['order_number']);
        $auth = $this->get(CustomerAuthenticator::class);

        $auth->login($bob);
        $e = $this->assertThrows(HttpException::class, fn () => $controller->order($request, $order['order_number']));
        $this->assertSame(404, $e->status);

        $auth->login($alice);
        $response = $controller->order($request, $order['order_number']);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString($order['order_number'], (string) $response->getBody());

        $auth->logout();
        $this->assertSame(302, $controller->order($request, $order['order_number'])->getStatusCode(), 'signed out: to the sign-in page');
    }

    public function testDeleteAccountAnonymisesAndKeepsOrders(): void
    {
        $customer = $this->register();
        $id = (int) $customer['id'];
        $order = $this->orderFor((string) $customer['email'], $id);
        $this->get(CustomerAddressRepository::class)->create($id, $this->address());
        $accounts = $this->get(CustomerAccounts::class);

        $this->assertThrows(RuntimeException::class, fn () => $accounts->deleteAccount($id, 'wrong'), 'not correct');
        $accounts->deleteAccount($id, self::PASSWORD);

        $row = $this->get(CustomerRepository::class)->find($id);
        $this->assertSame("deleted-{$id}@invalid", $row['email']);
        $this->assertSame('', $row['password_hash']);
        $this->assertEquals(0, $row['active']);
        $this->assertNotNull($row['deleted_at']);
        $this->assertSame(0, $this->get(CustomerAddressRepository::class)->count($id));
        $this->assertEquals($id, $this->orderRow($order['id'])['customer_id'], 'orders are kept');
        $this->assertFalse($this->get(CustomerAuthenticator::class)->attempt((string) $customer['email'], self::PASSWORD, $this->ip()));
        $this->assertNotNull($this->register((string) $customer['email']), 'the email can register again');
    }

    public function testSavedAddressesAndDefault(): void
    {
        $customer = $this->register();
        $id = (int) $customer['id'];
        $other = (int) $this->register()['id'];
        $repo = $this->get(CustomerAddressRepository::class);

        $first = $repo->create($id, $this->address(['label' => 'Home']));
        $second = $repo->create($id, $this->address(['label' => 'Work', 'address1' => 'Office 2']));
        $this->assertSame($first, (int) $repo->defaultFor($id)['id'], 'the first address is the default');

        $this->assertTrue($repo->setDefault($id, $second));
        $this->assertSame($second, (int) $repo->defaultFor($id)['id']);
        $this->assertTrue($repo->exists($id, $this->address(['address1' => 'Office 2'])));

        $this->assertFalse($repo->setDefault($other, $second), 'not their address');
        $this->assertFalse($repo->update($other, $second, $this->address(['address1' => 'Hijacked'])));
        $this->assertFalse($repo->delete($other, $second));
        $this->assertSame('Office 2', $repo->find($id, $second)['address1']);

        $this->assertTrue($repo->delete($id, $second));
        $this->assertSame($first, (int) $repo->defaultFor($id)['id'], 'another address becomes the default');
        $this->assertEquals(1, $repo->defaultFor($id)['is_default']);
    }

    /** @return array<string, mixed> customers row */
    private function register(?string $email = null): array
    {
        return $this->get(CustomerAccounts::class)->register([
            'name' => 'Test Customer', 'email' => $email ?? $this->email(),
            'password' => self::PASSWORD, 'password_confirm' => self::PASSWORD,
        ], $this->ip());
    }

    /** @return array{id: int, order_number: string, total: float, email: string} */
    private function orderFor(string $email, ?int $customerId): array
    {
        $ids = $this->makeProduct($this->sku('C'), 10.0, 5);

        return $this->get(CheckoutService::class)->placeOrder(
            ['customer_id' => $customerId] + $this->address(['email' => $email]),
            [['variant_id' => $ids['variant'], 'quantity' => 1, 'price' => 10.0, 'product_name' => 'Test product']],
            'standard',
            'bank_transfer'
        );
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function address(array $overrides = []): array
    {
        return $overrides + [
            'label' => '', 'name' => 'Test Customer', 'email' => 'x@example.com', 'phone' => '', 'address1' => 'Street 1',
            'address2' => '', 'city' => 'Lisbon', 'state' => '', 'postal_code' => '1000-001', 'country' => 'Portugal',
        ];
    }

    private function tokenCount(int $customerId, string $purpose, bool $openOnly = false): int
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM customer_tokens WHERE customer_id = ? AND purpose = ?' . ($openOnly ? ' AND used_at IS NULL' : ''));
        $stmt->execute([$customerId, $purpose]);

        return (int) $stmt->fetchColumn();
    }

    /** Forgets the authenticator's per-request cache, as a new request would. */
    private function reloadAuth(): void
    {
        $auth = $this->get(CustomerAuthenticator::class);
        (fn () => $this->loaded = false)->call($auth);
    }

    private function email(): string
    {
        return 'c-' . bin2hex(random_bytes(5)) . '@example.com';
    }

    /** A fresh IP per call, so rate limits of one test don't leak into another. */
    private function ip(): string
    {
        return '198.51.100.' . random_int(1, 254) . '-' . bin2hex(random_bytes(3));
    }
}
