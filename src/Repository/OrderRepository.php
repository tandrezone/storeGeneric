<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Orders and their items. Business rules (which status may follow which,
 * stock, emails) live in OrderService and PaymentRecorder; this class only
 * reads and writes rows.
 */
final class OrderRepository extends Repository
{
    public const STATUSES = ['pending', 'paid', 'processing', 'shipped', 'completed', 'cancelled', 'refunded'];
    public const PAYMENT_STATUSES = ['unpaid', 'paying', 'paid', 'failed', 'expired', 'refunded'];

    /**
     * @param array{status?: ?string, q?: string, from?: string, to?: string} $filters
     */
    public function countForAdmin(array $filters): int
    {
        [$where, $params] = $this->adminWhere($filters);

        return (int) $this->value('SELECT COUNT(*) FROM orders' . $where, $params);
    }

    /**
     * @param array{status?: ?string, q?: string, from?: string, to?: string} $filters
     * @return list<array<string, mixed>> one page, newest first
     */
    public function pageForAdmin(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->adminWhere($filters);
        $sql = 'SELECT id, order_number, email, ship_name, status, payment_status, payment_method, total, created_at FROM orders'
            . $where . ' ORDER BY created_at DESC, id DESC' . sprintf(' LIMIT %d OFFSET %d', $limit, $offset);

        return $this->all($sql, $params);
    }

    /**
     * Every order matching the admin filters (all columns), newest first — for the CSV export.
     *
     * @param array{status?: ?string, q?: string, from?: string, to?: string} $filters
     * @return \Generator<int, array<string, mixed>>
     */
    public function eachForAdmin(array $filters): \Generator
    {
        [$where, $params] = $this->adminWhere($filters);
        $stmt = $this->run('SELECT * FROM orders' . $where . ' ORDER BY created_at DESC, id DESC', $params);
        while (($row = $stmt->fetch()) !== false) {
            yield $row;
        }
    }

    /**
     * Items of every order matching the admin filters, with the order number — for the CSV export.
     *
     * @param array{status?: ?string, q?: string, from?: string, to?: string} $filters
     * @return \Generator<int, array<string, mixed>>
     */
    public function eachItemForAdmin(array $filters): \Generator
    {
        [$where, $params] = $this->adminWhere($filters);
        $stmt = $this->run('
            SELECT o.order_number, o.created_at, v.sku, i.*
            FROM order_items i
            JOIN orders o ON o.id = i.order_id
            LEFT JOIN product_variants v ON v.id = i.variant_id
            WHERE i.order_id IN (SELECT id FROM orders' . $where . ')
            ORDER BY o.created_at DESC, o.id DESC, i.id ASC
        ', $params);
        while (($row = $stmt->fetch()) !== false) {
            yield $row;
        }
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

    /** @return array<string, mixed>|null order with its `items`, when the email matches too (case-insensitive) */
    public function findByNumberAndEmail(string $orderNumber, string $email): ?array
    {
        return $this->withItems($this->one(
            'SELECT * FROM orders WHERE order_number = :n AND LOWER(email) = LOWER(:e)',
            ['n' => $orderNumber, 'e' => $email]
        ));
    }

    // ---- customer accounts (orders.customer_id) ----------------------------

    /** @return array<string, mixed>|null order with its `items`, only if it belongs to that customer account */
    public function findByNumberForCustomer(string $orderNumber, int $customerId): ?array
    {
        return $this->withItems($this->one(
            'SELECT * FROM orders WHERE order_number = :n AND customer_id = :c',
            ['n' => $orderNumber, 'c' => $customerId]
        ));
    }

    public function countForCustomer(int $customerId): int
    {
        return (int) $this->value('SELECT COUNT(*) FROM orders WHERE customer_id = :c', ['c' => $customerId]);
    }

    /** @return list<array<string, mixed>> one page of a customer's orders (no items), newest first */
    public function pageForCustomer(int $customerId, int $limit, int $offset = 0): array
    {
        return $this->all(
            'SELECT id, order_number, email, ship_name, status, payment_status, payment_method, total, created_at
             FROM orders WHERE customer_id = :c ORDER BY created_at DESC, id DESC' . sprintf(' LIMIT %d OFFSET %d', $limit, $offset),
            ['c' => $customerId]
        );
    }

    public function setCustomer(int $orderId, int $customerId): void
    {
        $this->run('UPDATE orders SET customer_id = :c WHERE id = :id', ['c' => $customerId, 'id' => $orderId]);
    }

    /**
     * Links guest orders placed with $email to a customer account. Only call
     * once the customer has proven they own that address (verified email).
     *
     * @return int number of orders linked
     */
    public function linkGuestOrders(int $customerId, string $email): int
    {
        return $this->run(
            'UPDATE orders SET customer_id = :c WHERE customer_id IS NULL AND LOWER(email) = LOWER(:e)',
            ['c' => $customerId, 'e' => trim($email)]
        )->rowCount();
    }

    /**
     * Locks the order row until the surrounding transaction ends, so two
     * payment events or admin actions can't change it at the same time.
     *
     * @return array<string, mixed>|null order with its `items`
     */
    public function lockForUpdate(int $id): ?array
    {
        return $this->withItems($this->one('SELECT * FROM orders WHERE id = :id FOR UPDATE', ['id' => $id]));
    }

    /** @return array<string, mixed>|null order with its `items` (locked, see lockForUpdate()) */
    public function lockByNumber(string $orderNumber): ?array
    {
        return $this->withItems($this->one('SELECT * FROM orders WHERE order_number = :n FOR UPDATE', ['n' => $orderNumber]));
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
            INSERT INTO order_items (order_id, variant_id, product_name, label, unit, unit_price, quantity, line_total, tax_amount)
            VALUES (:order_id, :variant_id, :product_name, :label, :unit, :unit_price, :quantity, :line_total, :tax_amount)
        ', ['order_id' => $orderId] + $item + ['tax_amount' => 0.0]);
    }

    /** Sets the order and/or payment status (null leaves it unchanged). */
    public function setStatuses(int $id, ?string $status, ?string $paymentStatus = null): void
    {
        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Unknown order status {$status}.");
        }
        if ($paymentStatus !== null && !in_array($paymentStatus, self::PAYMENT_STATUSES, true)) {
            throw new \InvalidArgumentException("Unknown payment status {$paymentStatus}.");
        }
        $this->run(
            'UPDATE orders SET status = COALESCE(:status, status), payment_status = COALESCE(:ps, payment_status) WHERE id = :id',
            ['status' => $status, 'ps' => $paymentStatus, 'id' => $id]
        );
    }

    /**
     * Flips stock_reserved 0 → 1. True only for the caller that flipped it,
     * so stock is never taken twice for the same order.
     */
    public function claimStockReservation(int $id): bool
    {
        return $this->run('UPDATE orders SET stock_reserved = 1 WHERE id = :id AND stock_reserved = 0', ['id' => $id])->rowCount() === 1;
    }

    /** Flips stock_reserved 1 → 0. True only for the caller that flipped it (stock is given back once). */
    public function releaseStockReservation(int $id): bool
    {
        return $this->run('UPDATE orders SET stock_reserved = 0 WHERE id = :id AND stock_reserved = 1', ['id' => $id])->rowCount() === 1;
    }

    /** Flips coupon_counted 1 → 0. True only for the caller that flipped it (a code's use is given back once). */
    public function releaseCouponUse(int $id): bool
    {
        return $this->run('UPDATE orders SET coupon_counted = 0 WHERE id = :id AND coupon_counted = 1', ['id' => $id])->rowCount() === 1;
    }

    /** Flips coupon_counted 0 → 1 for an order that has a coupon code. True only for the caller that flipped it. */
    public function claimCouponUse(int $id): bool
    {
        return $this->run(
            'UPDATE orders SET coupon_counted = 1 WHERE id = :id AND coupon_counted = 0 AND coupon_code IS NOT NULL',
            ['id' => $id]
        )->rowCount() === 1;
    }

    public function markShipped(int $id, string $trackingNumber, string $carrier): void
    {
        $this->run('
            UPDATE orders
            SET status = \'shipped\', tracking_number = :tracking, carrier = :carrier, shipped_at = COALESCE(shipped_at, NOW())
            WHERE id = :id
        ', ['tracking' => $trackingNumber !== '' ? $trackingNumber : null, 'carrier' => $carrier !== '' ? $carrier : null, 'id' => $id]);
    }

    public function updateTracking(int $id, string $trackingNumber, string $carrier): void
    {
        $this->run(
            'UPDATE orders SET tracking_number = :tracking, carrier = :carrier WHERE id = :id',
            ['tracking' => $trackingNumber !== '' ? $trackingNumber : null, 'carrier' => $carrier !== '' ? $carrier : null, 'id' => $id]
        );
    }

    /**
     * Unpaid online orders older than $hours (abandoned at the provider).
     *
     * @param list<string> $methods payment method ids to include
     * @return list<int> order ids
     */
    public function findStaleUnpaid(array $methods, int $hours): array
    {
        if ($methods === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($methods), '?'));

        return array_map('intval', array_column($this->all("
            SELECT id FROM orders
            WHERE status = 'pending' AND payment_status IN ('unpaid', 'paying')
              AND payment_method IN ({$placeholders})
              AND created_at < (NOW() - INTERVAL ? HOUR)
        ", [...$methods, $hours]), 'id'));
    }

    /**
     * @param array{status?: ?string, q?: string, from?: string, to?: string} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function adminWhere(array $filters): array
    {
        $where = [];
        $params = [];
        if (($filters['status'] ?? null) !== null && in_array($filters['status'], self::STATUSES, true)) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(order_number LIKE :q1 OR email LIKE :q2 OR ship_name LIKE :q3)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like];
        }
        if (($filters['from'] ?? '') !== '') {
            $where[] = 'created_at >= :from';
            $params['from'] = $filters['from'] . ' 00:00:00';
        }
        if (($filters['to'] ?? '') !== '') {
            $where[] = 'created_at < DATE_ADD(:to, INTERVAL 1 DAY)';
            $params['to'] = $filters['to'];
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params];
    }

    /**
     * @param array<string, mixed>|null $order
     * @return array<string, mixed>|null
     */
    private function withItems(?array $order): ?array
    {
        if ($order !== null) {
            $order['items'] = $this->all('SELECT * FROM order_items WHERE order_id = :id ORDER BY id ASC', ['id' => $order['id']]);
        }

        return $order;
    }
}
