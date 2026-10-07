<?php

declare(strict_types=1);

namespace App\Repository;

final class OrderRepository extends Repository
{
    public const STATUSES = ['pending', 'paid', 'processing', 'shipped', 'completed', 'cancelled'];

    /** @return list<array<string, mixed>> newest first, optionally filtered by status */
    public function findForAdmin(?string $status = null): array
    {
        $sql = 'SELECT id, order_number, email, ship_name, status, payment_status, payment_method, total, created_at FROM orders';
        $params = [];
        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $sql .= ' WHERE status = :status';
            $params['status'] = $status;
        }

        return $this->all($sql . ' ORDER BY created_at DESC', $params);
    }

    /** @return array<string, mixed>|null order with its `items` */
    public function find(int $id): ?array
    {
        return $this->withItems($this->one('SELECT * FROM orders WHERE id = :id', ['id' => $id]));
    }

    /** @return array<string, mixed>|null order with its `items` */
    public function findByNumber(string $orderNumber): ?array
    {
        return $this->withItems($this->one('SELECT * FROM orders WHERE order_number = :n', ['n' => $orderNumber]));
    }

    /**
     * Inserts the order row; call inside a transaction together with addItem().
     *
     * @param array<string, mixed> $order column => value
     */
    public function insert(array $order): int
    {
        $columns = array_keys($order);
        $this->run(
            'INSERT INTO orders (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')',
            $order
        );

        return $this->lastId();
    }

    /** @param array<string, mixed> $item */
    public function addItem(int $orderId, array $item): void
    {
        $this->run('
            INSERT INTO order_items (order_id, variant_id, product_name, label, unit, unit_price, quantity, line_total)
            VALUES (:order_id, :variant_id, :product_name, :label, :unit, :unit_price, :quantity, :line_total)
        ', ['order_id' => $orderId] + $item);
    }

    public function updateStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }
        $this->run('UPDATE orders SET status = :status WHERE id = :id', ['status' => $status, 'id' => $id]);

        return true;
    }

    /** Sets the payment status and the matching order status (paid → paid, failed/expired → cancelled). */
    public function updatePaymentStatus(string $orderNumber, string $paymentStatus): void
    {
        $orderStatus = match ($paymentStatus) {
            'paid' => 'paid',
            'failed', 'expired' => 'cancelled',
            default => 'pending',
        };
        $this->run(
            'UPDATE orders SET payment_status = :ps, status = :s WHERE order_number = :n',
            ['ps' => $paymentStatus, 's' => $orderStatus, 'n' => $orderNumber]
        );
    }

    /**
     * @param array<string, mixed>|null $order
     * @return array<string, mixed>|null
     */
    private function withItems(?array $order): ?array
    {
        if ($order !== null) {
            $order['items'] = $this->all('SELECT * FROM order_items WHERE order_id = :id', ['id' => $order['id']]);
        }

        return $order;
    }
}
