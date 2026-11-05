<?php

declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\Database;
use PDO;
use Throwable;

/**
 * Everything the store holds about a signed-in customer, for the GDPR
 * "right of access / data portability" download (Account → Profile). Secrets
 * (password hash, tokens) are never included. Sections that belong to
 * optional features are read defensively: a store that hasn't run the
 * migration for one simply leaves it out.
 */
final class CustomerDataExport
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed> JSON-serialisable */
    public function build(int $customerId): array
    {
        $profile = $this->row('SELECT id, email, name, phone, locale, email_verified_at, last_login_at, created_at FROM customers WHERE id = :id', ['id' => $customerId]);

        $orders = $this->rows('SELECT * FROM orders WHERE customer_id = :id ORDER BY created_at, id', ['id' => $customerId]);
        foreach ($orders as &$order) {
            $order['items'] = $this->rows(
                'SELECT product_name, label, unit, unit_price, quantity, line_total, tax_amount FROM order_items WHERE order_id = :o ORDER BY id',
                ['o' => $order['id']]
            );
        }
        unset($order);

        $data = [
            'exported_at' => date('c'),
            'profile'     => $profile,
            'addresses'   => $this->rows('SELECT label, name, phone, address1, address2, city, state, postal_code, country, is_default, created_at FROM customer_addresses WHERE customer_id = :id ORDER BY id', ['id' => $customerId]),
            'orders'      => $orders,
        ];

        // Optional features (each added by its own migration).
        $email = (string) ($profile['email'] ?? '');
        foreach ([
            'reviews'               => ['SELECT product_id, rating, title, body, status, created_at FROM product_reviews WHERE customer_id = :id ORDER BY id', ['id' => $customerId]],
            'wishlist'              => ['SELECT product_id, created_at FROM wishlist_items WHERE customer_id = :id ORDER BY id', ['id' => $customerId]],
            'stock_alerts'          => ['SELECT product_id, variant_id, email, created_at, notified_at FROM stock_alerts WHERE email = :e ORDER BY id', ['e' => $email]],
            'newsletter'            => ['SELECT email, locale, confirmed_at, unsubscribed_at, created_at FROM newsletter_subscribers WHERE email = :e', ['e' => $email]],
            'return_requests'       => ['SELECT r.id, o.order_number, r.status, r.reason, r.details, r.created_at FROM return_requests r JOIN orders o ON o.id = r.order_id WHERE o.customer_id = :id ORDER BY r.id', ['id' => $customerId]],
        ] as $key => [$sql, $params]) {
            $rows = $this->optionalRows($sql, $params);
            if ($rows !== null) {
                $data[$key] = $rows;
            }
        }

        return $data;
    }

    public function toJson(int $customerId): string
    {
        return (string) json_encode($this->build($customerId), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** @param array<string, mixed> $params @return array<string, mixed>|null */
    private function row(string $sql, array $params): ?array
    {
        $row = $this->rows($sql, $params)[0] ?? null;

        return $row;
    }

    /** @param array<string, mixed> $params @return list<array<string, mixed>> */
    private function rows(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed> $params @return list<array<string, mixed>>|null null when the table doesn't exist */
    private function optionalRows(string $sql, array $params): ?array
    {
        try {
            return $this->rows($sql, $params);
        } catch (Throwable) {
            return null;
        }
    }
}
