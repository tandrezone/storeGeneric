<?php

declare(strict_types=1);

namespace App\Repository;

/** Return requests (return_requests, return_request_items). Rules live in ReturnService. */
final class ReturnRepository extends Repository
{
    public const STATUSES = ['requested', 'approved', 'rejected', 'received', 'refunded'];

    /** @return array<string, mixed>|null the request with its `items` (name, label, quantity) */
    public function find(int $id): ?array
    {
        $row = $this->one('SELECT r.*, o.order_number, o.email, o.ship_name, o.locale FROM return_requests r JOIN orders o ON o.id = r.order_id WHERE r.id = :id', ['id' => $id]);

        return $row === null ? null : $this->withItems([$row])[0];
    }

    /** @return list<array<string, mixed>> an order's requests, newest first, with items */
    public function forOrder(int $orderId): array
    {
        return $this->withItems($this->all('SELECT * FROM return_requests WHERE order_id = :o ORDER BY id DESC', ['o' => $orderId]));
    }

    /**
     * Units of each order item already in a request that wasn't rejected.
     *
     * @return array<int, int> order_item_id => quantity
     */
    public function requestedQuantities(int $orderId): array
    {
        $rows = $this->all("
            SELECT i.order_item_id, SUM(i.quantity) AS qty
            FROM return_request_items i JOIN return_requests r ON r.id = i.return_id
            WHERE r.order_id = :o AND r.status <> 'rejected'
            GROUP BY i.order_item_id
        ", ['o' => $orderId]);

        return array_map('intval', array_column($rows, 'qty', 'order_item_id'));
    }

    /**
     * @param array<int, int> $items order_item_id => quantity
     */
    public function create(int $orderId, string $reason, ?string $details, array $items): int
    {
        return $this->db->transaction(function () use ($orderId, $reason, $details, $items): int {
            $this->run('INSERT INTO return_requests (order_id, reason, details) VALUES (:o, :r, :d)', ['o' => $orderId, 'r' => $reason, 'd' => $details]);
            $id = $this->lastId();
            foreach ($items as $itemId => $quantity) {
                $this->run('INSERT INTO return_request_items (return_id, order_item_id, quantity) VALUES (:r, :i, :q)', ['r' => $id, 'i' => $itemId, 'q' => $quantity]);
            }

            return $id;
        });
    }

    public function setStatus(int $id, string $status, ?string $response): void
    {
        $this->run('UPDATE return_requests SET status = :s, admin_response = :r WHERE id = :id', ['s' => $status, 'r' => $response, 'id' => $id]);
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->value('SELECT COUNT(*) FROM return_requests WHERE status = :s', ['s' => $status]);
    }

    /** @return list<array<string, mixed>> newest first, with order number and items */
    public function pageForAdmin(?string $status, int $limit, int $offset = 0): array
    {
        $where = $status !== null ? 'WHERE r.status = :s' : '';
        $rows = $this->all(
            "SELECT r.*, o.order_number, o.email, o.ship_name FROM return_requests r JOIN orders o ON o.id = r.order_id {$where} ORDER BY r.id DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $status !== null ? ['s' => $status] : []
        );

        return $this->withItems($rows);
    }

    public function countForAdmin(?string $status): int
    {
        return $status !== null ? $this->countByStatus($status) : (int) $this->value('SELECT COUNT(*) FROM return_requests');
    }

    /**
     * @param list<array<string, mixed>> $requests
     * @return list<array<string, mixed>>
     */
    private function withItems(array $requests): array
    {
        if ($requests === []) {
            return [];
        }
        $in = implode(',', array_map(static fn (array $r): int => (int) $r['id'], $requests));
        $rows = $this->all("
            SELECT i.return_id, i.order_item_id, i.quantity, oi.product_name, oi.label, oi.unit
            FROM return_request_items i JOIN order_items oi ON oi.id = i.order_item_id
            WHERE i.return_id IN ({$in}) ORDER BY i.id
        ");
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['return_id']][] = $row;
        }
        foreach ($requests as &$request) {
            $request['items'] = $grouped[(int) $request['id']] ?? [];
        }

        return $requests;
    }
}
