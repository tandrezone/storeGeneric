<?php

declare(strict_types=1);

namespace App\Repository;

/** Internal staff notes on orders (order_notes table). */
final class OrderNoteRepository extends Repository
{
    public const MAX_LENGTH = 2000;

    /** @return list<array<string, mixed>> oldest first */
    public function forOrder(int $orderId): array
    {
        return $this->all('SELECT * FROM order_notes WHERE order_id = :o ORDER BY created_at, id', ['o' => $orderId]);
    }

    public function add(int $orderId, ?string $author, string $body): int
    {
        $this->run('INSERT INTO order_notes (order_id, author, body) VALUES (:o, :a, :b)', [
            'o' => $orderId,
            'a' => $author,
            'b' => mb_substr(trim($body), 0, self::MAX_LENGTH),
        ]);

        return $this->lastId();
    }

    public function delete(int $id, int $orderId): void
    {
        $this->run('DELETE FROM order_notes WHERE id = :id AND order_id = :o', ['id' => $id, 'o' => $orderId]);
    }

    /** @return array<int, int> order id => number of notes, for the given orders */
    public function countsFor(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $orderIds));

        return array_map('intval', array_column(
            $this->all("SELECT order_id, COUNT(*) AS n FROM order_notes WHERE order_id IN ($in) GROUP BY order_id"),
            'n',
            'order_id'
        ));
    }
}
