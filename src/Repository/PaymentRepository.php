<?php

declare(strict_types=1);

namespace App\Repository;

/** Log of payment events per order (one row per webhook / manual mark-as-paid). */
final class PaymentRepository extends Repository
{
    public function record(int $orderId, string $provider, string $status, ?string $reference, float $amount, ?string $currency, string $raw): void
    {
        $this->run('
            INSERT INTO payments (order_id, provider, track_id, status, amount, currency, raw_response)
            VALUES (:order_id, :provider, :track_id, :status, :amount, :currency, :raw)
        ', [
            'order_id' => $orderId,
            'provider' => $provider,
            'track_id' => $reference,
            'status'   => $status,
            'amount'   => $amount,
            'currency' => $currency,
            'raw'      => $raw,
        ]);
    }

    /**
     * True when this exact event (provider + reference + status) is already
     * logged for the order — i.e. a webhook retry. Call with the order row
     * locked so two retries can't both pass the check.
     */
    public function exists(int $orderId, string $provider, ?string $reference, string $status): bool
    {
        return $this->value('
            SELECT 1 FROM payments
            WHERE order_id = :order_id AND provider = :provider AND status = :status AND track_id <=> :track_id
            LIMIT 1
        ', ['order_id' => $orderId, 'provider' => $provider, 'status' => $status, 'track_id' => $reference]) !== false;
    }

    /** @return list<array<string, mixed>> the order's payment events, oldest first (admin) */
    public function forOrder(int $orderId): array
    {
        return $this->all(
            'SELECT provider, track_id, status, amount, currency, created_at FROM payments WHERE order_id = :id ORDER BY id ASC',
            ['id' => $orderId]
        );
    }
}
