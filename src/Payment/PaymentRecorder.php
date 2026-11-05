<?php

declare(strict_types=1);

namespace App\Payment;

use App\I18n\Translator;
use App\Infrastructure\Database;
use App\Repository\OrderNoteRepository;
use App\Repository\OrderRepository;
use App\Repository\PaymentRepository;
use App\Service\CouponService;
use App\Service\GiftCardService;
use App\Service\OrderNotifier;
use App\Service\OrderStock;
use App\Service\StoreSettings;
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
 *    (the same goes for its discount-code use, see CouponService). If its
 *    stock was sold meanwhile, or its gift card no longer covers its part,
 *    the order is held as "pending" for review (note + admin email) instead
 *    of being confirmed to the customer.
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
        private readonly GiftCardService $giftCards,
        private readonly OrderNotifier $notifier,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly OrderNoteRepository $notes,
        private readonly Translator $translator,
        private readonly StoreSettings $store,
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

        $problems = [];
        [$outcome, $orderId] = $this->db->transaction(function () use (&$problems, $orderNumber, $provider, $status, $reference, $amount, $currency, $raw): array {
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
                    // A released order takes its stock, discount-code use and gift-card amount again.
                    $short = $this->stock->reserve($order);
                    $this->coupons->reclaim($order);
                    $giftCardShort = $this->giftCards->reclaim($order);
                    $problems = $this->problems($order, $short, $giftCardShort);

                    if ($problems !== []) {
                        // Paid, but it can't be fulfilled as ordered: hold it for the admin instead of confirming it.
                        $this->orders->setStatuses($orderId, 'pending', 'paid');
                        $this->notes->add($orderId, null, implode("\n", $problems));

                        return ['review', $orderId];
                    }

                    // A pending (or released) order becomes paid; one an admin already moved on keeps its status.
                    $newStatus = in_array($order['status'], ['pending', 'cancelled'], true) ? 'paid' : null;
                    $this->orders->setStatuses($orderId, $newStatus, 'paid');

                    return ['paid', $orderId];

                case 'failed':
                case 'expired':
                    if ($order['status'] !== 'pending') {
                        return ['logged', $orderId];
                    }
                    $this->orders->setStatuses($orderId, 'cancelled', $status);
                    $this->stock->release($order);
                    $this->coupons->release($order);
                    $this->giftCards->release($order);

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
        if ($outcome === 'review') {
            $this->logger->warning('Order paid after it was released, but can no longer be fulfilled as ordered — held for review', $context + ['problems' => $problems]);
            $this->notifier->paymentNeedsReview($orderId, $problems);
        }

        return true;
    }

    /**
     * Admin-facing lines (store language) for what a paid-after-release order is missing.
     *
     * @param array<string, mixed> $order
     * @param list<array{name: string, sku: string, needed: int, available: int}> $short
     * @return list<string>
     */
    private function problems(array $order, array $short, float $giftCardShort): array
    {
        if ($short === [] && $giftCardShort <= 0.0) {
            return [];
        }

        return $this->translator->withLocale($this->store->language(), function () use ($order, $short, $giftCardShort): array {
            $lines = [$this->translator->trans('Paid after the order was released: check it before fulfilling, and contact the customer or refund.')];
            foreach ($short as $line) {
                $lines[] = $this->translator->trans('Not enough stock for {product}: {needed} ordered, {available} left.', [
                    'product' => trim($line['name'] . ($line['sku'] !== '' ? ' (' . $line['sku'] . ')' : '')),
                    'needed' => $line['needed'], 'available' => $line['available'],
                ]);
            }
            if ($giftCardShort > 0.0) {
                $lines[] = $this->translator->trans('Gift card {code} no longer covers its part: {amount} was not collected.', [
                    'code' => (string) $order['gift_card_code'],
                    'amount' => number_format($giftCardShort, 2) . ' ' . strtoupper($this->config->get('STORE_CURRENCY', 'EUR')),
                ]);
            }

            return $lines;
        });
    }

    /**
     * The paid amount covers the order total, in the store currency. A "paid"
     * event without an amount or currency can't be checked and is refused
     * (logged as amount_mismatch) rather than trusted.
     *
     * @param array<string, mixed> $order
     */
    private function amountMatches(array $order, ?float $amount, string $currency): bool
    {
        $storeCurrency = strtoupper($this->config->get('STORE_CURRENCY', 'EUR'));
        if ($amount === null || $currency !== $storeCurrency) {
            return false;
        }

        return Money::covers($amount, (float) $order['total'], $storeCurrency);
    }
}
