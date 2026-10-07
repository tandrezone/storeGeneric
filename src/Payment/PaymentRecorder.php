<?php

declare(strict_types=1);

namespace App\Payment;

use App\Infrastructure\Database;
use App\Repository\OrderRepository;
use App\Repository\PaymentRepository;
use App\Service\CouponService;
use App\Service\OrderNotifier;
use App\Service\OrderStock;
use App\Support\Config;
use App\Support\Money;
use Psr\Log\LoggerInterface;

/**
 * The single place a payment outcome is written. With the order row locked:
 *  - a repeated event (same provider + reference + status) is ignored;
 *  - a "paid" event whose amount or currency doesn't match the order is
 *    logged as `amount_mismatch` and doesn't mark the order paid;
 *  - once an order is paid (or refunded) later events are only logged —
 *    a duplicate "paid" never moves a shipped order back to "paid";
 *  - failed / expired payments cancel a pending order and give its stock back,
 *    and a released order that gets paid after all takes its stock again
 *    (the same goes for its discount-code use, see CouponService).
 */
final class PaymentRecorder
{
    public const STATUSES = ['unpaid', 'paying', 'paid', 'failed', 'expired'];

    public function __construct(
        private readonly Database $db,
        private readonly OrderRepository $orders,
        private readonly PaymentRepository $payments,
        private readonly OrderStock $stock,
        private readonly CouponService $coupons,
        private readonly OrderNotifier $notifier,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return bool true when the event was accepted (including harmless duplicates) */
    public function record(
        string $orderNumber,
        string $provider,
        string $status,
        string $reference = '',
        ?float $amount = null,
        string $currency = '',
        string $raw = '',
    ): bool {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }
        $reference = $reference !== '' ? $reference : null;
        $currency = strtoupper(trim($currency));
        $context = ['order' => $orderNumber, 'provider' => $provider, 'status' => $status, 'reference' => $reference];

        [$outcome, $orderId] = $this->db->transaction(function () use ($orderNumber, $provider, $status, $reference, $amount, $currency, $raw): array {
            $order = $this->orders->lockByNumber($orderNumber);
            if ($order === null) {
                return ['unknown', 0];
            }
            $orderId = (int) $order['id'];

            $logged = $status === 'paid' && !$this->amountMatches($order, $amount, $currency) ? 'amount_mismatch' : $status;
            if ($this->payments->exists($orderId, $provider, $reference, $logged)) {
                return ['duplicate', $orderId];
            }
            $this->payments->record($orderId, $provider, $logged, $reference, $amount ?? (float) $order['total'], $currency !== '' ? $currency : null, $raw);

            if ($logged === 'amount_mismatch') {
                return ['mismatch', $orderId];
            }
            if (in_array($order['payment_status'], ['paid', 'refunded'], true)) {
                return ['logged', $orderId]; // already settled: never downgrade or re-process
            }

            switch ($status) {
                case 'paid':
                    // A pending (or released) order becomes paid; one an admin already moved on keeps its status.
                    $newStatus = in_array($order['status'], ['pending', 'cancelled'], true) ? 'paid' : null;
                    $this->orders->setStatuses($orderId, $newStatus, 'paid');
                    $this->stock->reserve($order);
                    $this->coupons->reclaim($order);

                    return ['paid', $orderId];

                case 'failed':
                case 'expired':
                    if ($order['status'] !== 'pending') {
                        return ['logged', $orderId];
                    }
                    $this->orders->setStatuses($orderId, 'cancelled', $status);
                    $this->stock->release($order);
                    $this->coupons->release($order);

                    return ['cancelled', $orderId];

                default: // unpaid, paying
                    if ($order['status'] === 'pending') {
                        $this->orders->setStatuses($orderId, null, $status);
                    }

                    return ['updated', $orderId];
            }
        });

        if ($outcome === 'unknown') {
            $this->logger->warning('Payment event for unknown order', $context);

            return false;
        }
        if ($outcome === 'mismatch') {
            $this->logger->warning('Payment amount or currency does not match the order — not marked paid', $context + [
                'amount' => $amount, 'currency' => $currency,
            ]);

            return false;
        }

        $this->logger->info('Payment recorded', $context + ['outcome' => $outcome]);
        if ($outcome === 'paid') {
            $this->notifier->orderPaid($orderId);
        }

        return true;
    }

    /**
     * The paid amount covers the order total, in the store currency. An event
     * without an amount (or currency) can't be checked and is accepted.
     *
     * @param array<string, mixed> $order
     */
    private function amountMatches(array $order, ?float $amount, string $currency): bool
    {
        $storeCurrency = strtoupper($this->config->get('STORE_CURRENCY', 'EUR'));
        if ($currency !== '' && $currency !== $storeCurrency) {
            return false;
        }

        return $amount === null || Money::covers($amount, (float) $order['total'], $storeCurrency);
    }
}
