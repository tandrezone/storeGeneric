<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Support\Config;
use PDO;
use RuntimeException;

/**
 * Lazily opened PDO connection (MySQL/MariaDB) — nothing connects until a
 * repository actually runs a query.
 */
final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        if (!$this->config->has('DB_USER')) {
            throw new RuntimeException('DB_USER / DB_PASS are not set.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $this->config->get('DB_HOST', '127.0.0.1'),
            $this->config->get('DB_PORT', '3306'),
            $this->config->get('DB_NAME', 'online_store')
        );

        return $this->pdo = new PDO($dsn, $this->config->get('DB_USER'), $this->config->get('DB_PASS'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    /**
     * Runs $work inside a transaction, committing on success and rolling
     * back (then rethrowing) on any exception.
     *
     * @template T
     * @param callable(PDO): T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $result = $work($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
