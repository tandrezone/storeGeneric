<?php

declare(strict_types=1);

namespace App\Repository;

/** "Email me when it's back in stock" requests (stock_alerts). */
final class StockAlertRepository extends Repository
{
    /** Adds (or re-arms) an alert; returns its cancel token. */
    public function subscribe(int $productId, string $email, ?string $locale): string
    {
        $token = bin2hex(random_bytes(16));
        $this->run('
            INSERT INTO stock_alerts (product_id, email, locale, token) VALUES (:p, :e, :l, :t)
            ON DUPLICATE KEY UPDATE notified_at = NULL, locale = VALUES(locale)
        ', ['p' => $productId, 'e' => $email, 'l' => $locale, 't' => $token]);

        return (string) $this->value('SELECT token FROM stock_alerts WHERE product_id = :p AND email = :e', ['p' => $productId, 'e' => $email]);
    }

    public function cancel(string $token): bool
    {
        return $this->run('DELETE FROM stock_alerts WHERE token = :t', ['t' => $token])->rowCount() > 0;
    }

    /**
     * Pending alerts whose product is on sale again: visible, with an active, priced
     * variant that has stock.
     *
     * @return list<array<string, mixed>>
     */
    public function due(int $limit = 500): array
    {
        return $this->all("
            SELECT a.id, a.product_id, a.email, a.locale, a.token, p.name AS product_name
            FROM stock_alerts a
            JOIN products p ON p.id = a.product_id AND p.is_active = 1 AND p.import_status = 'approved'
            WHERE a.notified_at IS NULL
              AND EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1 AND v.price > 0 AND v.stock > 0)
            ORDER BY a.id LIMIT " . max(1, $limit));
    }

    public function markNotified(int $id): void
    {
        $this->run('UPDATE stock_alerts SET notified_at = NOW() WHERE id = :id', ['id' => $id]);
    }

    public function pendingCount(): int
    {
        return (int) $this->value('SELECT COUNT(*) FROM stock_alerts WHERE notified_at IS NULL');
    }
}
