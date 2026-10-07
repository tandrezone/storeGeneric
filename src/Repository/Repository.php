<?php

declare(strict_types=1);

namespace App\Repository;

use App\Infrastructure\Database;
use PDO;
use PDOStatement;

/** Base class: every repository talks to the database through these helpers. */
abstract class Repository
{
    public function __construct(protected readonly Database $db)
    {
    }

    protected function pdo(): PDO
    {
        return $this->db->pdo();
    }

    /** @param array<string|int, mixed> $params */
    protected function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    protected function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return list<array<string, mixed>>
     */
    protected function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param array<string|int, mixed> $params */
    protected function value(string $sql, array $params = []): mixed
    {
        return $this->run($sql, $params)->fetchColumn();
    }

    protected function lastId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }
}
