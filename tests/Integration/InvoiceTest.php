<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\OrderRepository;
use App\Service\InvoiceService;
use App\Service\OrderService;
use RuntimeException;
use Tests\IntegrationTestCase;

final class InvoiceTest extends IntegrationTestCase
{
    private function paidOrder(): array
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5, 'Café Ação');
        $order = $this->placeOrder($ids['variant'], 2, 10.0);
        $this->get(OrderService::class)->markPaid($order['id']);

        return $this->get(OrderRepository::class)->find($order['id']);
    }

    public function testNoInvoiceBeforePayment(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $order = $this->get(OrderRepository::class)->find($this->placeOrder($ids['variant'], 1, 10.0)['id']);
        $this->assertThrows(RuntimeException::class, fn () => $this->get(InvoiceService::class)->pdf($order), 'once the order is paid');
    }

    public function testInvoiceIsNumberedOnceAndRebuiltIdentically(): void
    {
        $service = $this->get(InvoiceService::class);
        $order = $this->paidOrder();

        $first = $service->pdf($order);
        $again = $service->pdf($order);
        $this->assertSame($first['number'], $again['number']);
        $this->assertSame(sprintf('INV-%d-', date('Y')), substr($first['number'], 0, 9));
        $this->assertTrue(str_starts_with($first['content'], '%PDF'));

        $other = $service->pdf($this->paidOrder());
        $this->assertSame((int) substr($first['number'], -5) + 1, (int) substr($other['number'], -5), 'sequence has no gaps');
    }

    public function testCreditNoteNeedsARefundAndHasItsOwnSequence(): void
    {
        $service = $this->get(InvoiceService::class);
        $order = $this->paidOrder();
        $this->assertThrows(RuntimeException::class, fn () => $service->pdf($order, InvoiceService::CREDIT_NOTE), 'refunded');

        $this->get(OrderService::class)->refund($order['id'], true);
        $refunded = $this->get(OrderRepository::class)->find($order['id']);
        $note = $service->pdf($refunded, InvoiceService::CREDIT_NOTE);
        $this->assertSame('CN-', substr($note['number'], 0, 3));
        $this->assertSame(1, (int) $this->pdo()->query("SELECT COUNT(*) FROM invoices WHERE order_id = {$order['id']} AND kind = 'credit_note'")->fetchColumn());
    }

    public function testNumberFormat(): void
    {
        $this->assertSame('INV-2026-00042', InvoiceService::number('invoice', 2026, 42));
        $this->assertSame('CN-2026-00001', InvoiceService::number('credit_note', 2026, 1));
    }
}
