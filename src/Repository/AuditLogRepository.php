<?php

declare(strict_types=1);

namespace App\Repository;

/** Admin activity log (admin_audit_log), newest first. */
final class AuditLogRepository extends Repository
{
    /** @param array{user_id: ?int, username: ?string, action: string, entity_type: ?string, entity_id: ?string, summary: string, details: ?string, ip_address: ?string} $entry */
    public function insert(array $entry): void
    {
        $this->run('
            INSERT INTO admin_audit_log (user_id, username, action, entity_type, entity_id, summary, details, ip_address)
            VALUES (:user_id, :username, :action, :entity_type, :entity_id, :summary, :details, :ip_address)
        ', $entry);
    }

    /**
     * @param array{username: ?string, action: ?string, from: string, to: string} $filters
     */
    public function countFiltered(array $filters): int
    {
        [$where, $params] = $this->where($filters);

        return (int) $this->value('SELECT COUNT(*) FROM admin_audit_log' . $where, $params);
    }

    /**
     * @param array{username: ?string, action: ?string, from: string, to: string} $filters
     * @return list<array<string, mixed>>
     */
    public function page(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($filters);

        return $this->all(sprintf('
            SELECT id, user_id, username, action, entity_type, entity_id, summary, details, ip_address, created_at
            FROM admin_audit_log%s
            ORDER BY created_at DESC, id DESC
            LIMIT %d OFFSET %d
        ', $where, max(1, $limit), max(0, $offset)), $params);
    }

    /** @return list<string> admins that appear in the log (failed logins with unknown names are left out) */
    public function usernames(): array
    {
        return array_map('strval', $this->run(
            'SELECT DISTINCT username FROM admin_audit_log WHERE user_id IS NOT NULL AND username IS NOT NULL ORDER BY username'
        )->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<string> */
    public function actions(): array
    {
        return array_map('strval', $this->run(
            'SELECT DISTINCT action FROM admin_audit_log ORDER BY action'
        )->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param array{username: ?string, action: ?string, from: string, to: string} $filters
     * @return array{0: string, 1: array<string, string>}
     */
    private function where(array $filters): array
    {
        $conditions = [];
        $params = [];
        if (($filters['username'] ?? null) !== null) {
            $conditions[] = 'username = :username';
            $params['username'] = $filters['username'];
        }
        if (($filters['action'] ?? null) !== null) {
            $conditions[] = 'action = :action';
            $params['action'] = $filters['action'];
        }
        if ($filters['from'] !== '') {
            $conditions[] = 'created_at >= :from';
            $params['from'] = $filters['from'] . ' 00:00:00';
        }
        if ($filters['to'] !== '') {
            $conditions[] = 'created_at < DATE_ADD(:to, INTERVAL 1 DAY)';
            $params['to'] = $filters['to'];
        }

        return [$conditions ? ' WHERE ' . implode(' AND ', $conditions) : '', $params];
    }
}
