<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;
use Throwable;

/** Storefront analytics events (page_views table). */
final class PageViewRepository extends Repository
{
    public const EVENT_TYPES = ['visit', 'product_view', 'checkout_shipping', 'checkout_payment'];

    /** Records an event. Never throws — analytics must never break the page it tracks. */
    public function record(string $sessionId, string $eventType, ?int $productId, string $path): void
    {
        if (!in_array($eventType, self::EVENT_TYPES, true)) {
            return;
        }

        try {
            $this->run(
                'INSERT INTO page_views (session_id, event_type, product_id, path) VALUES (:s, :e, :p, :path)',
                ['s' => $sessionId, 'e' => $eventType, 'p' => $productId, 'path' => substr($path, 0, 255)]
            );
        } catch (Throwable) {
            // ignored on purpose
        }
    }

    /** @return array<string, array{total: int, unique_sessions: int}> totals per event type over the last $days days */
    public function summary(int $days): array
    {
        $stmt = $this->pdo()->prepare('
            SELECT event_type, COUNT(*) AS total, COUNT(DISTINCT session_id) AS unique_sessions
            FROM page_views
            WHERE created_at >= (NOW() - INTERVAL :days DAY)
            GROUP BY event_type
        ');
        $stmt->bindValue('days', $days, PDO::PARAM_INT);
        $stmt->execute();

        $summary = array_fill_keys(self::EVENT_TYPES, ['total' => 0, 'unique_sessions' => 0]);
        foreach ($stmt->fetchAll() as $row) {
            $summary[$row['event_type']] = ['total' => (int) $row['total'], 'unique_sessions' => (int) $row['unique_sessions']];
        }

        return $summary;
    }

    /** @return list<array{day: string, total: int, unique_sessions: int}> daily visits, oldest first, zero-filled */
    public function dailyVisits(int $days): array
    {
        $stmt = $this->pdo()->prepare("
            SELECT DATE(created_at) AS day, COUNT(*) AS total, COUNT(DISTINCT session_id) AS unique_sessions
            FROM page_views
            WHERE event_type = 'visit' AND created_at >= (NOW() - INTERVAL :days DAY)
            GROUP BY DATE(created_at)
        ");
        $stmt->bindValue('days', $days, PDO::PARAM_INT);
        $stmt->execute();

        $byDay = [];
        foreach ($stmt->fetchAll() as $row) {
            $byDay[$row['day']] = ['total' => (int) $row['total'], 'unique_sessions' => (int) $row['unique_sessions']];
        }

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', (int) strtotime("-{$i} days"));
            $series[] = ['day' => $day] + ($byDay[$day] ?? ['total' => 0, 'unique_sessions' => 0]);
        }

        return $series;
    }

    /** @return list<array<string, mixed>> most-viewed products */
    public function topProducts(int $days, int $limit): array
    {
        $stmt = $this->pdo()->prepare("
            SELECT pv.product_id, p.name, COUNT(*) AS views, COUNT(DISTINCT pv.session_id) AS unique_sessions
            FROM page_views pv
            JOIN products p ON p.id = pv.product_id
            WHERE pv.event_type = 'product_view' AND pv.created_at >= (NOW() - INTERVAL :days DAY)
            GROUP BY pv.product_id, p.name
            ORDER BY views DESC
            LIMIT :limit
        ");
        $stmt->bindValue('days', $days, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> most-visited paths */
    public function topPaths(int $days, int $limit): array
    {
        $stmt = $this->pdo()->prepare("
            SELECT path, COUNT(*) AS total, COUNT(DISTINCT session_id) AS unique_sessions
            FROM page_views
            WHERE event_type = 'visit' AND created_at >= (NOW() - INTERVAL :days DAY)
            GROUP BY path
            ORDER BY total DESC
            LIMIT :limit
        ");
        $stmt->bindValue('days', $days, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
