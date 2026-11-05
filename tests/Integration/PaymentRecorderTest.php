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

    public function testPaidAfterReleaseWithItsStockSoldIsHeldForReview(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 1);
        $order = $this->placeOrder($ids['variant'], 1, 10.0, 'stripe');
        $recorder = $this->get(PaymentRecorder::class);
        $this->assertTrue($recorder->record($order['order_number'], 'stripe', 'expired', 'cs_r1'));
        $this->assertSame(1, $this->stockOf($ids['variant']), 'released');
        $this->pdo()->exec('UPDATE product_variants SET stock = 0 WHERE id = ' . $ids['variant']); // the last one sold to someone else

        $this->assertTrue($recorder->record($order['order_number'], 'stripe', 'paid', 'cs_r2', $order['total'], 'EUR'));

        $row = $this->orderRow($order['id']);
        $this->assertSame('paid', $row['payment_status'], 'the money is recorded');
        $this->assertSame('pending', $row['status'], 'but the order is held, not confirmed');
        $this->assertSame(0, $this->stockOf($ids['variant']));
        $note = (string) $this->pdo()->query('SELECT body FROM order_notes WHERE order_id = ' . $order['id'])->fetchColumn();
        $this->assertStringContainsString('Not enough stock', $note);
    }

    public function testPaidAfterReleaseWithItsGiftCardSpentIsHeldForReview(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $order = $this->placeOrder($ids['variant'], 1, 10.0, 'stripe');
        $card = $this->get(\App\Service\GiftCardService::class)->issue(4.0, null, null, null, null, 'test');
        // As if checkout had taken 4.00 from the card and released it again when the order expired.
        $this->pdo()->exec("UPDATE orders SET gift_card_code = '{$card['code']}', gift_card_amount = 4.00, gift_card_counted = 0, status = 'cancelled', payment_status = 'expired' WHERE id = {$order['id']}");
        $this->pdo()->exec("UPDATE gift_cards SET balance = 1.00 WHERE id = {$card['id']}"); // mostly spent elsewhere since

        $this->assertTrue($this->get(PaymentRecorder::class)->record($order['order_number'], 'stripe', 'paid', 'cs_g1', $order['total'], 'EUR'));

        $this->assertSame('pending', $this->orderRow($order['id'])['status']);
        $this->assertEquals(0.0, $this->pdo()->query("SELECT balance FROM gift_cards WHERE id = {$card['id']}")->fetchColumn(), 'what was left is taken');
        $note = (string) $this->pdo()->query('SELECT body FROM order_notes WHERE order_id = ' . $order['id'])->fetchColumn();
        $this->assertStringContainsString('3.00 EUR was not collected', $note);
    }

    public function testPaidEventWithoutAmountOrCurrencyIsRefused(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $order = $this->placeOrder($ids['variant'], 1, 10.0, 'stripe');
        $recorder = $this->get(PaymentRecorder::class);

        $this->assertFalse($recorder->record($order['order_number'], 'stripe', 'paid', 'cs_n1', null, 'EUR'));
        $this->assertFalse($recorder->record($order['order_number'], 'stripe', 'paid', 'cs_n2', $order['total'], ''));
        $this->assertSame('unpaid', $this->orderRow($order['id'])['payment_status']);
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
