<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\OrderRepository;
use App\Repository\VariantRepository;
use Psr\Log\LoggerInterface;

/**
 * Stock held by an order. Checkout reserves it when the order is placed;
 * it is released at most once (cancel, failed/expired payment, refund with
 * restock) and taken again if a released order gets paid after all. The
 * orders.stock_reserved flag is flipped atomically, so nothing is ever
 * taken or given back twice. Call inside a transaction.
 */
final class OrderStock
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly VariantRepository $variants,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param array<string, mixed> $order with `items` */
    public function reserve(array $order): void
    {
        if (!$this->orders->claimStockReservation((int) $order['id'])) {
            return;
        }
        foreach ($order['items'] as $item) {
            $row = $this->variants->lockForUpdate((int) $item['variant_id']);
            if ($row !== null && (int) $row['stock'] < (int) $item['quantity']) {
                $this->logger->warning('Paid order needs more stock than is left', [
                    'order' => $order['order_number'], 'variant' => $item['variant_id'],
                    'stock' => $row['stock'], 'quantity' => $item['quantity'],
                ]);
            }
            $this->variants->decrementStock((int) $item['variant_id'], (int) $item['quantity']);
        }
    }

    /** @param array<string, mixed> $order with `items` */
    public function release(array $order): void
    {
        if (!$this->orders->releaseStockReservation((int) $order['id'])) {
            return;
        }
        foreach ($order['items'] as $item) {
            $this->variants->incrementStock((int) $item['variant_id'], (int) $item['quantity']);
        }
    }
}
