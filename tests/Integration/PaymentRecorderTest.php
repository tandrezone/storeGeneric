<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Payment\PaymentRecorder;
use Tests\IntegrationTestCase;

final class PaymentRecorderTest extends IntegrationTestCase
{
    public function testDuplicatePaidEventsAreRecordedOnce(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $order = $this->placeOrder($ids['variant'], 1, 10.0, 'stripe');
        $recorder = $this->get(PaymentRecorder::class);

        $this->assertTrue($recorder->record($order['order_number'], 'stripe', 'paid', 'cs_1', $order['total'], 'EUR'));
        $this->assertTrue($recorder->record($order['order_number'], 'stripe', 'paid', 'cs_1', $order['total'], 'EUR'), 'a retried webhook is accepted');

        $row = $this->orderRow($order['id']);
        $this->assertSame('paid', $row['payment_status']);
        $this->assertSame('paid', $row['status']);
        $this->assertSame(1, $this->paymentCount($order['id'], 'paid'));
        $this->assertSame(4, $this->stockOf($ids['variant']), 'stock taken once (at checkout)');
    }

    public function testZeroTotalOfflineOrderGetsConfirmationAndAdminAlert(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $order = $this->placeOrder($ids['variant'], 1, 10.0, 'bank_transfer');
        // Fully discounted (what CheckoutController records as paid straight away).
        $this->pdo()->exec('UPDATE orders SET total = 0 WHERE id = ' . $order['id']);

        $log = $this->get(\App\Support\Paths::class)->var('log/mail-' . date('Y-m-d') . '.log');
        clearstatcache();
        $offset = is_file($log) ? (int) filesize($log) : 0;
        $this->assertTrue($this->get(PaymentRecorder::class)->record($order['order_number'], 'free', 'paid', 'no-payment-due', 0.0, 'EUR'));
        $mail = (string) file_get_contents($log, false, null, $offset);

        $this->assertStringContainsString("Order {$order['order_number']} confirmed", $mail);
        $this->assertStringContainsString("New order {$order['order_number']}", $mail);
        $this->assertStringNotContainsString('Payment received', $mail);
        $this->assertSame('paid', $this->orderRow($order['id'])['payment_status']);
    }

    public function testAmountOrCurrencyMismatchDoesNotMarkPaid(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $order = $this->placeOrder($ids['variant'], 2, 10.0, 'stripe');
        $recorder = $this->get(PaymentRecorder::class);

        $this->assertFalse($recorder->record($order['order_number'], 'stripe', 'paid', 'cs_2', $order['total'] - 1, 'EUR'));
        $this->assertFalse($recorder->record($order['order_number'], 'stripe', 'paid', 'cs_3', $order['total'], 'USD'));

        $row = $this->orderRow($order['id']);
        $this->assertSame('unpaid', $row['payment_status']);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(2, $this->paymentCount($order['id'], 'amount_mismatch'));
        $this->assertSame(0, $this->paymentCount($order['id'], 'paid'));
    }

    public function testFailedPaymentCancelsAndRestocksOnce(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $order = $this->placeOrder($ids['variant'], 3, 10.0, 'stripe');
        $recorder = $this->get(PaymentRecorder::class);
        $this->assertSame(2, $this->stockOf($ids['variant']));

        $this->assertTrue($recorder->record($order['order_number'], 'stripe', 'failed', 'cs_4'));
        $this->assertTrue($recorder->record($order['order_number'], 'stripe', 'expired', 'cs_4'));

        $row = $this->orderRow($order['id']);
        $this->assertSame('cancelled', $row['status']);
        $this->assertSame(5, $this->stockOf($ids['variant']), 'given back exactly once');

        // Paid after all: the order takes its stock again, and a later "paid" duplicate changes nothing.
        $this->assertTrue($recorder->record($order['order_number'], 'stripe', 'paid', 'cs_5', $order['total'], 'EUR'));
        $this->assertTrue($recorder->record($order['order_number'], 'stripe', 'paid', 'cs_6', $order['total'], 'EUR'));
        $this->assertSame('paid', $this->orderRow($order['id'])['payment_status']);
        $this->assertSame(2, $this->stockOf($ids['variant']));
    }

    public function testUnknownOrderAndStatus(): void
    {
        $recorder = $this->get(PaymentRecorder::class);
        $this->assertFalse($recorder->record('ORD-NOPE', 'stripe', 'paid', 'x', 1.0, 'EUR'));
        $this->assertFalse($recorder->record('ORD-NOPE', 'stripe', 'bogus'));
    }

    private function paymentCount(int $orderId, string $status): int
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM payments WHERE order_id = ? AND status = ?');
        $stmt->execute([$orderId, $status]);

        return (int) $stmt->fetchColumn();
    }
}
