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
}
