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

    /**
     * Takes the order's stock again. Returns the lines that were short (stock
     * sold to someone else while the order was released), so the caller can
     * hold the order for review instead of fulfilling it.
     *
     * @param array<string, mixed> $order with `items`
     * @return list<array{name: string, sku: string, needed: int, available: int}>
     */
    public function reserve(array $order): array
    {
        if (!$this->orders->claimStockReservation((int) $order['id'])) {
            return [];
        }
        $short = [];
        foreach ($order['items'] as $item) {
            $row = $this->variants->lockForUpdate((int) $item['variant_id']);
            if ($row === null || (int) $row['stock'] < (int) $item['quantity']) {
                $short[] = [
                    'name'      => (string) ($item['product_name'] ?? $item['name'] ?? ''),
                    'sku'       => (string) ($item['sku'] ?? ''),
                    'needed'    => (int) $item['quantity'],
                    'available' => $row === null ? 0 : (int) $row['stock'],
                ];
                $this->logger->warning('Paid order needs more stock than is left', [
                    'order' => $order['order_number'], 'variant' => $item['variant_id'],
                    'stock' => $row['stock'] ?? null, 'quantity' => $item['quantity'],
                ]);
            }
            if ($row !== null) {
                $this->variants->decrementStock((int) $item['variant_id'], (int) $item['quantity']);
            }
        }

        return $short;
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
