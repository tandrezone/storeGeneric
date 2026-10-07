<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Read-only figures for the admin dashboard. Revenue counts orders whose
 * payment_status is 'paid' (refunded orders drop out), by the day the order
 * was placed; days are the database server's calendar days.
 */
final class DashboardRepository extends Repository
{
    /** Methods paid outside the store, where an admin has to confirm the payment. */
    public const MANUAL_METHODS = ['bank_transfer', 'cod'];

    /** The database's current date (Y-m-d), so day buckets match DATE(created_at). */
    public function today(): string
    {
        return (string) $this->value('SELECT CURDATE()');
    }

    /**
     * Orders placed, paid orders and paid revenue since $days - 1 days ago
     * (1 = today only).
     *
     * @return array{orders: int, paid_orders: int, revenue: float, average: float}
     */
    public function totals(int $days): array
    {
        $row = $this->one("
            SELECT COUNT(*) AS orders,
                   COALESCE(SUM(payment_status = 'paid'), 0) AS paid_orders,
                   COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total END), 0) AS revenue
            FROM orders
            WHERE created_at >= CURDATE() - INTERVAL :days DAY
        ", ['days' => max(0, $days - 1)]) ?? [];

        $paid = (int) ($row['paid_orders'] ?? 0);
        $revenue = (float) ($row['revenue'] ?? 0);

        return [
            'orders'      => (int) ($row['orders'] ?? 0),
            'paid_orders' => $paid,
            'revenue'     => round($revenue, 2),
            'average'     => $paid > 0 ? round($revenue / $paid, 2) : 0.0,
        ];
    }

    /**
     * Paid revenue and order count per day for the last $days days, oldest
     * first, including days without orders.
     *
     * @return list<array{day: string, revenue: float, orders: int}>
     */
    public function dailyRevenue(int $days): array
    {
        $rows = $this->all("
            SELECT DATE(created_at) AS day, SUM(total) AS revenue, COUNT(*) AS orders
            FROM orders
            WHERE payment_status = 'paid' AND created_at >= CURDATE() - INTERVAL :days DAY
            GROUP BY DATE(created_at)
        ", ['days' => max(0, $days - 1)]);

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row['day']] = $row;
        }

        $today = new \DateTimeImmutable($this->today());
        $points = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = $today->modify("-{$i} day")->format('Y-m-d');
            $points[] = [
                'day'     => $day,
                'revenue' => round((float) ($byDay[$day]['revenue'] ?? 0), 2),
                'orders'  => (int) ($byDay[$day]['orders'] ?? 0),
            ];
        }

        return $points;
    }

    /**
     * Counts of orders waiting for an admin.
     *
     * @return array{to_ship: int, awaiting_payment: int}
     */
    public function awaitingCounts(): array
    {
        [$in, $params] = $this->manualMethods();
        $row = $this->one("
            SELECT COALESCE(SUM(payment_status = 'paid' AND status IN ('paid', 'processing')), 0) AS to_ship,
                   COALESCE(SUM(payment_status = 'unpaid' AND status = 'pending' AND payment_method IN ({$in})), 0) AS awaiting_payment
            FROM orders
        ", $params) ?? [];

        return [
            'to_ship'          => (int) ($row['to_ship'] ?? 0),
            'awaiting_payment' => (int) ($row['awaiting_payment'] ?? 0),
        ];
    }

    /**
     * Oldest orders first: paid but not shipped yet, and bank-transfer / cash-
     * on-delivery orders still waiting (COD ships before it is paid).
     *
     * @return list<array<string, mixed>>
     */
    public function awaitingOrders(int $limit): array
    {
        [$in, $params] = $this->manualMethods();

        return $this->all(sprintf("
            SELECT id, order_number, ship_name, email, status, payment_status, payment_method, total, created_at,
                   CASE WHEN payment_status = 'paid' THEN 'ship' ELSE 'payment' END AS waiting_for
            FROM orders
            WHERE (payment_status = 'paid' AND status IN ('paid', 'processing'))
               OR (payment_status = 'unpaid' AND status = 'pending' AND payment_method IN ({$in}))
            ORDER BY created_at ASC, id ASC
            LIMIT %d
        ", max(1, $limit)), $params);
    }

    /**
     * Best sellers by units in paid orders of the last $days days.
     *
     * @return list<array{product_id: int, name: string, units: int, revenue: float}>
     */
    public function topProducts(int $days, int $limit): array
    {
        $rows = $this->all(sprintf("
            SELECT v.product_id, COALESCE(p.name, MAX(oi.product_name)) AS name,
                   SUM(oi.quantity) AS units, SUM(oi.line_total) AS revenue
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            JOIN product_variants v ON v.id = oi.variant_id
            LEFT JOIN products p ON p.id = v.product_id
            WHERE o.payment_status = 'paid' AND o.created_at >= CURDATE() - INTERVAL :days DAY
            GROUP BY v.product_id, p.name
            ORDER BY units DESC, revenue DESC
            LIMIT %d
        ", max(1, $limit)), ['days' => max(0, $days - 1)]);

        return array_map(static fn (array $r) => [
            'product_id' => (int) $r['product_id'],
            'name'       => (string) $r['name'],
            'units'      => (int) $r['units'],
            'revenue'    => round((float) $r['revenue'], 2),
        ], $rows);
    }

    /** @return list<array<string, mixed>> */
    public function recentOrders(int $limit): array
    {
        return $this->all(sprintf('
            SELECT id, order_number, ship_name, email, status, payment_status, payment_method, total, created_at
            FROM orders
            ORDER BY created_at DESC, id DESC
            LIMIT %d
        ', max(1, $limit)));
    }

    /** @return array{0: string, 1: array<string, string>} "IN (...)" placeholders and their values */
    private function manualMethods(): array
    {
        $params = [];
        foreach (self::MANUAL_METHODS as $i => $method) {
            $params['m' . $i] = $method;
        }

        return [implode(', ', array_map(static fn (string $k) => ':' . $k, array_keys($params))), $params];
    }
}
