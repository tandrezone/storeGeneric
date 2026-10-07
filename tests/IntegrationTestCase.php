<?php

declare(strict_types=1);

namespace Tests;

use App\Console\Command\ReimportDatabaseCommand;
use App\Console\Input;
use App\Console\Output;
use App\Infrastructure\Database;
use App\Kernel;
use DI\Container;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tests that run against a real MariaDB/MySQL database. They are skipped
 * unless TEST_DB_NAME is set (plus TEST_DB_HOST, TEST_DB_PORT, TEST_DB_USER,
 * TEST_DB_PASS as needed). The database is rebuilt from schema.sql +
 * migrations once per run, so it must be a throwaway one: its name has to
 * contain "test".
 */
abstract class IntegrationTestCase extends TestCase
{
    private static ?Container $container = null;
    private static ?string $unavailable = null;

    public function setUp(): void
    {
        if (self::$unavailable !== null) {
            $this->skip(self::$unavailable);
        }
        if (self::$container === null) {
            self::boot();
            if (self::$unavailable !== null) {
                $this->skip(self::$unavailable);
            }
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    protected function get(string $class): object
    {
        return self::$container->get($class);
    }

    protected function pdo(): PDO
    {
        return $this->get(Database::class)->pdo();
    }

    /** Inserts a category, product and variant; returns the ids. @return array{category: int, product: int, variant: int} */
    protected function makeProduct(string $sku, float $price = 10.0, int $stock = 5, string $name = 'Test product'): array
    {
        $pdo = $this->pdo();
        $pdo->prepare('INSERT INTO categories (name, slug) VALUES (?, ?)')->execute(['Cat ' . $sku, 'cat-' . strtolower($sku) . '-' . bin2hex(random_bytes(3))]);
        $category = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO products (category_id, name, short_description, long_description, import_status) VALUES (?, ?, 'Short', '<p>Long</p>', 'approved')")
            ->execute([$category, $name]);
        $product = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO product_variants (product_id, sku, label, unit, price, stock) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$product, $sku, '1', 'pc', $price, $stock]);

        return ['category' => $category, 'product' => $product, 'variant' => (int) $pdo->lastInsertId()];
    }

    /**
     * Places an order through CheckoutService (standard shipping, seeded by schema.sql).
     *
     * @return array{id: int, order_number: string, total: float, email: string}
     */
    protected function placeOrder(int $variantId, int $quantity, float $price, string $paymentMethod = 'bank_transfer'): array
    {
        return $this->get(\App\Service\CheckoutService::class)->placeOrder(
            [
                'name' => 'Test Buyer', 'email' => 'buyer@example.com', 'phone' => '', 'address1' => 'Street 1',
                'address2' => '', 'city' => 'Lisbon', 'state' => '', 'postal_code' => '1000-001', 'country' => 'Portugal',
            ],
            [['variant_id' => $variantId, 'quantity' => $quantity, 'price' => $price, 'product_name' => 'Test product']],
            'standard',
            $paymentMethod
        );
    }

    /** @return array<string, mixed> the orders row */
    protected function orderRow(int $orderId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);

        return $stmt->fetch() ?: [];
    }

    protected function stockOf(int $variantId): int
    {
        $stmt = $this->pdo()->prepare('SELECT stock FROM product_variants WHERE id = ?');
        $stmt->execute([$variantId]);

        return (int) $stmt->fetchColumn();
    }

    /** A unique SKU, so tests don't collide inside one database. */
    protected function sku(string $prefix = 'T'): string
    {
        return $prefix . '-' . strtoupper(bin2hex(random_bytes(4)));
    }

    private static function boot(): void
    {
        $name = (string) getenv('TEST_DB_NAME');
        if ($name === '') {
            self::$unavailable = 'TEST_DB_NAME is not set (no test database)';

            return;
        }
        if (!str_contains(strtolower($name), 'test')) {
            self::$unavailable = 'TEST_DB_NAME must contain "test" — the database is wiped';

            return;
        }
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::$unavailable = 'pdo_mysql is not installed';

            return;
        }

        // Real environment variables win over .env (App\Support\Config).
        $env = [
            'DB_HOST'         => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'DB_PORT'         => getenv('TEST_DB_PORT') ?: '3306',
            'DB_NAME'         => $name,
            'DB_USER'         => getenv('TEST_DB_USER') ?: 'root',
            'DB_PASS'         => (string) getenv('TEST_DB_PASS'),
            'STORE_CURRENCY'  => 'EUR',
            'MAIL_TRANSPORT'  => 'log',
            'STORE_EMAIL'     => 'store@example.com',
            'APP_SECRET'      => 'test-secret-test-secret-test-secret',
            'APP_URL'         => 'http://localhost',
            'APP_DEBUG'       => 'false',
        ];
        foreach ($env as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }

        $container = Kernel::boot(dirname(__DIR__))->container();
        $container->set(LoggerInterface::class, new NullLogger());

        try {
            $container->get(Database::class)->pdo()->query('SELECT 1');
        } catch (\PDOException $e) {
            self::$unavailable = 'cannot connect to the test database: ' . $e->getMessage();

            return;
        }

        $out = fopen('php://memory', 'w+b');
        $err = fopen('php://memory', 'w+b');
        $code = $container->get(ReimportDatabaseCommand::class)->run(new Input([], ['force' => true]), new Output($out, $err));
        if ($code !== 0) {
            rewind($err);
            self::$unavailable = 'could not build the test schema: ' . stream_get_contents($err);

            return;
        }

        self::$container = $container;
    }
}
