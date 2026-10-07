<?php

declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\Database;
use App\Payment\PaymentMethod;
use App\Payment\PaymentRecorder;
use App\Payment\PaymentRegistry;
use App\Repository\OrderRepository;
use App\Repository\PaymentRepository;
use App\Support\Config;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Admin order actions and their allowed transitions. Every change runs with
 * the order row locked; emails go out after the transaction commits.
 *
 *   pending ──pay──▶ paid ──▶ processing ──ship──▶ shipped ──▶ completed
 *      │               └──────────────ship──────────▲
 *      └─cancel─▶ cancelled           paid/processing/shipped/completed ──refund──▶ refunded
 *
 * Cancelling an unpaid order (or its payment failing / expiring) also gives
 * back its discount-code use (CouponService::release()).
 *
 * Cash-on-delivery and bank-transfer orders may also go pending → processing
 * → shipped before they are paid.
 *
 * @throws RuntimeException from each action, with a message for the admin
 */
final class OrderService
{
    /** set_status targets allowed from each status (shipping, cancelling and refunding have their own actions). */
    private const NEXT = [
        'pending'    => ['processing'],
        'paid'       => ['processing', 'completed'],
        'processing' => ['completed'],
        'shipped'    => ['completed'],
        'completed'  => [],
        'cancelled'  => [],
        'refunded'   => [],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly OrderRepository $orders,
        private readonly PaymentRepository $payments,
        private readonly PaymentRecorder $recorder,
        private readonly PaymentRegistry $methods,
        private readonly OrderStock $stock,
        private readonly CouponService $coupons,
        private readonly OrderNotifier $notifier,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $order
     * @return list<string> statuses the admin can pick next in "Update status"
     */
    public function nextStatuses(array $order): array
    {
        return array_values(array_filter(
            self::NEXT[$order['status']] ?? [],
            fn (string $next) => $this->statusAllowed($order, $next)
        ));
    }

    /** @param array<string, mixed> $order */
    public function canMarkPaid(array $order): bool
    {
        return !in_array($order['payment_status'], ['paid', 'refunded'], true) && $order['status'] !== 'refunded';
    }

    /** @param array<string, mixed> $order */
    public function canCancel(array $order): bool
    {
        return in_array($order['status'], ['pending', 'processing'], true)
            && !in_array($order['payment_status'], ['paid', 'refunded'], true);
    }

    /** @param array<string, mixed> $order */
    public function canRefund(array $order): bool
    {
        return $order['payment_status'] === 'paid' && $order['status'] !== 'refunded';
    }

    /** @param array<string, mixed> $order */
    public function canShip(array $order): bool
    {
        return in_array($order['status'], ['paid', 'processing'], true)
            || ($order['status'] === 'pending' && $this->isOffline($order) && $order['payment_status'] !== 'refunded');
    }

    /** @param array<string, mixed> $order */
    public function canEditTracking(array $order): bool
    {
        return in_array($order['status'], ['shipped', 'completed'], true);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->transaction(function () use ($id, $status): void {
            $order = $this->lock($id);
            if (!in_array($status, self::NEXT[$order['status']] ?? [], true) || !$this->statusAllowed($order, $status)) {
                throw new RuntimeException("An order can't go from {$order['status']} to {$status}.");
            }
            $this->orders->setStatuses($id, $status);
        });
    }

    /** Records a manual payment (bank transfer / cash on delivery received). */
    public function markPaid(int $id): void
    {
        $order = $this->orders->find($id) ?? throw new RuntimeException('Order not found.');
        if (!$this->canMarkPaid($order)) {
            throw new RuntimeException('This order is already paid or refunded.');
        }
        $this->recorder->record(
            (string) $order['order_number'],
            'manual',
            'paid',
            'admin',
            (float) $order['total'],
            strtoupper($this->config->get('STORE_CURRENCY', 'EUR'))
        );
    }

    /** Cancels an unpaid order and puts its stock back. */
    public function cancel(int $id, bool $notify = true): void
    {
        $this->db->transaction(function () use ($id): void {
            $order = $this->lock($id);
            if (!$this->canCancel($order)) {
                throw new RuntimeException('Only unpaid orders that haven\'t shipped can be cancelled. Use "Refund" for paid orders.');
            }
            $this->orders->setStatuses($id, 'cancelled');
            $this->stock->release($order);
            $this->coupons->release($order);
        });
        $this->logger->info('Order cancelled by admin', ['order_id' => $id]);
        if ($notify) {
            $this->notifier->orderCancelled($id);
        }
    }

    /** The payment provider couldn't be started: the customer never got to pay. */
    public function cancelUnstarted(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $order = $this->lock($id);
            if ($order['status'] === 'pending' && $order['payment_status'] !== 'paid') {
                $this->orders->setStatuses($id, 'cancelled', 'failed');
                $this->stock->release($order);
                $this->coupons->release($order);
            }
        });
    }

    /** Marks a paid order refunded (money is returned at the provider), optionally restocking it. */
    public function refund(int $id, bool $restock): void
    {
        $this->db->transaction(function () use ($id, $restock): void {
            $order = $this->lock($id);
            if (!$this->canRefund($order)) {
                throw new RuntimeException('Only paid orders can be refunded.');
            }
            $this->orders->setStatuses($id, 'refunded', 'refunded');
            $this->payments->record(
                $id,
                'manual',
                'refunded',
                'admin',
                (float) $order['total'],
                strtoupper($this->config->get('STORE_CURRENCY', 'EUR')),
                $restock ? 'restocked' : 'not restocked'
            );
            if ($restock) {
                $this->stock->release($order);
            }
        });
        $this->logger->info('Order refunded by admin', ['order_id' => $id, 'restock' => $restock]);
        $this->notifier->orderRefunded($id);
    }

    /** Marks the order shipped with its tracking details and emails the customer. */
    public function ship(int $id, string $trackingNumber, string $carrier): void
    {
        $this->db->transaction(function () use ($id, $trackingNumber, $carrier): void {
            $order = $this->lock($id);
            if (!$this->canShip($order)) {
                throw new RuntimeException("A {$order['status']} order can't be shipped.");
            }
            $this->orders->markShipped($id, $trackingNumber, $carrier);
        });
        $this->notifier->orderShipped($id);
    }

    public function updateTracking(int $id, string $trackingNumber, string $carrier): void
    {
        $this->db->transaction(function () use ($id, $trackingNumber, $carrier): void {
            if (!$this->canEditTracking($this->lock($id))) {
                throw new RuntimeException('Tracking can only be edited once the order has shipped.');
            }
            $this->orders->updateTracking($id, $trackingNumber, $carrier);
        });
    }

    /**
     * Cancels online-payment orders still unpaid after $hours (abandoned at the
     * provider, e.g. PayPal, which sends no expiry event) and releases their stock.
     *
     * @return int how many were expired
     */
    public function expireStale(int $hours): int
    {
        $online = array_keys(array_filter($this->methods->all(), static fn (PaymentMethod $m) => !$m->isOffline()));
        $count = 0;
        foreach ($this->orders->findStaleUnpaid($online, max(1, $hours)) as $id) {
            $expired = $this->db->transaction(function () use ($id): bool {
                $order = $this->lock($id);
                if ($order['status'] !== 'pending' || !in_array($order['payment_status'], ['unpaid', 'paying'], true)) {
                    return false;
                }
                $this->orders->setStatuses($id, 'cancelled', 'expired');
                $this->stock->release($order);
                $this->coupons->release($order);

                return true;
            });
            if ($expired) {
                $count++;
                $this->logger->info('Unpaid order expired', ['order_id' => $id]);
            }
        }

        return $count;
    }

    /** @param array<string, mixed> $order */
    private function statusAllowed(array $order, string $next): bool
    {
        return match ($next) {
            'processing' => $order['status'] !== 'pending' || $this->isOffline($order),
            'completed'  => $order['payment_status'] === 'paid',
            default      => true,
        };
    }

    /** @param array<string, mixed> $order */
    private function isOffline(array $order): bool
    {
        return $this->methods->get((string) $order['payment_method'])?->isOffline() ?? false;
    }

    /** @return array<string, mixed> */
    private function lock(int $id): array
    {
        return $this->orders->lockForUpdate($id) ?? throw new RuntimeException('Order not found.');
    }
}
