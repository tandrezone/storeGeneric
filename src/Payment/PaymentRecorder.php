<?php

declare(strict_types=1);

namespace App\Payment;

use App\Infrastructure\Database;
use App\Repository\OrderRepository;
use App\Repository\PaymentRepository;
use App\Repository\VariantRepository;
use Psr\Log\LoggerInterface;

/**
 * The single place a payment outcome is written: logs it in `payments`,
 * moves the order's payment status, and reduces stock the first time an
 * order becomes paid (repeat "paid" notifications don't reduce it again,
 * and a paid order is never downgraded by a late event).
 */
final class PaymentRecorder
{
    public const STATUSES = ['unpaid', 'paying', 'paid', 'failed', 'expired'];

    public function __construct(
        private readonly Database $db,
        private readonly OrderRepository $orders,
        private readonly PaymentRepository $payments,
        private readonly VariantRepository $variants,
        private readonly LoggerInterface $logger,
    ) {
    }

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

        $order = $this->orders->findByNumber($orderNumber);
        if ($order === null) {
            $this->logger->warning('Payment event for unknown order', ['order' => $orderNumber, 'provider' => $provider]);

            return false;
        }

        $this->db->transaction(function () use ($order, $orderNumber, $provider, $status, $reference, $amount, $currency, $raw): void {
            $this->payments->record(
                (int) $order['id'],
                $provider,
                $status,
                $reference !== '' ? $reference : null,
                $amount ?? (float) $order['total'],
                $currency !== '' ? $currency : null,
                $raw
            );

            $alreadyPaid = $order['payment_status'] === 'paid';
            if ($alreadyPaid && $status !== 'paid') {
                return;
            }

            $this->orders->updatePaymentStatus($orderNumber, $status);

            if ($status === 'paid' && !$alreadyPaid) {
                foreach ($order['items'] as $item) {
                    $this->variants->decrementStock((int) $item['variant_id'], (int) $item['quantity']);
                }
                // TODO: send the confirmation email to $order['email'] here.
            }
        });

        $this->logger->info('Payment recorded', ['order' => $orderNumber, 'provider' => $provider, 'status' => $status]);

        return true;
    }
}
