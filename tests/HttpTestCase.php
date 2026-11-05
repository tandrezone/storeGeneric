<?php

declare(strict_types=1);

namespace Tests;

use App\Kernel;
use App\Repository\AdminUserRepository;
use App\Repository\CustomerRepository;
use App\Security\Csrf;
use DI\Container;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Integration tests that go through the whole middleware pipeline
 * (routing, session, CSRF, admin login wall, controllers, Twig). In the CLI
 * the PHP session is the plain $_SESSION array, which these helpers
 * reset between tests, sign an admin in, and read the CSRF token from.
 */
abstract class HttpTestCase extends IntegrationTestCase
{
    private ?Kernel $kernel = null;

    /** @var array<string, mixed> container entries (class => instance) replaced in every request's container, e.g. a fake HTTP client */
    protected array $overrides = [];

    public function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->kernel = Kernel::boot(dirname(__DIR__));
        $this->kernel->container()->set(LoggerInterface::class, new NullLogger());
    }

    public function tearDown(): void
    {
        $_SESSION = [];
        $_COOKIE = [];
        parent::tearDown();
    }

    protected function http(): Container
    {
        return $this->kernel->container();
    }

    /**
     * @param array<string, mixed> $body  form fields (POST); the CSRF token is added unless 'csrf_token' is set
     * @param array<string, mixed> $query
     */
    protected function request(string $method, string $path, array $body = [], array $query = [], array $headers = []): ResponseInterface
    {
        $factory = new Psr17Factory();
        $uri = 'http://localhost' . $path . ($query === [] ? '' : '?' . http_build_query($query));
        $request = $factory->createServerRequest($method, $uri, ['REMOTE_ADDR' => '203.0.113.9'])->withQueryParams($query);
        if ($method !== 'GET') {
            $body += [Csrf::FIELD => $this->http()->get(Csrf::class)->token()];
            $request = $request->withParsedBody($body);
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        // A fresh container per request, like PHP-FPM: services cache per-request state (who is signed in…).
        $kernel = Kernel::boot(dirname(__DIR__));
        $kernel->container()->set(LoggerInterface::class, new NullLogger());
        foreach ($this->overrides as $id => $service) {
            $kernel->container()->set($id, $service);
        }

        return $kernel->handle($request);
    }

    protected function get_(string $path, array $query = []): ResponseInterface
    {
        return $this->request('GET', $path, [], $query);
    }

    protected function post(string $path, array $body = []): ResponseInterface
    {
        return $this->request('POST', $path, $body);
    }

    protected function html(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }

    protected function assertStatus(int $expected, ResponseInterface $response): void
    {
        $this->assertSame($expected, $response->getStatusCode(), 'HTTP status of ' . substr(strip_tags($this->html($response)), 0, 200));
    }

    protected function assertRedirectsTo(string $fragment, ResponseInterface $response): void
    {
        $this->assertTrue(in_array($response->getStatusCode(), [301, 302, 303], true), 'a redirect, got ' . $response->getStatusCode());
        $this->assertStringContainsString($fragment, $response->getHeaderLine('Location'));
    }

    /** Signs in an admin (creates one) for the following requests. */
    protected function loginAdmin(string $role = 'owner'): int
    {
        $users = $this->http()->get(AdminUserRepository::class);
        $name = 'tester' . bin2hex(random_bytes(3));
        $hash = password_hash('a-very-long-test-password', PASSWORD_DEFAULT);
        $id = $users->create($name, $name . '@example.com', $hash, $role);
        $_SESSION['admin_user_id'] = $id;
        $_SESSION['admin_password_fp'] = hash('sha256', 'admin-session|' . $hash);

        return $id;
    }

    /** Creates a customer account and signs it in for the following requests. @return array{id: int, email: string} */
    protected function loginCustomer(string $password = 'a-very-long-test-password'): array
    {
        $email = 'cust' . bin2hex(random_bytes(3)) . '@example.com';
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $id = $this->http()->get(CustomerRepository::class)->create($email, 'Test Customer', $hash);
        $_SESSION['customer_id'] = $id;
        $_SESSION['customer_password_fp'] = hash('sha256', 'customer-session|' . $hash);

        return ['id' => $id, 'email' => $email];
    }

    /** Flash messages waiting in the session (what the next page shows). */
    protected function flashes(): array
    {
        return (array) ($_SESSION['_flash'] ?? []);
    }
}
