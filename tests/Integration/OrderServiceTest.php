<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Service\OrderService;
use RuntimeException;
use Tests\IntegrationTestCase;

final class OrderServiceTest extends IntegrationTestCase
{
    public function testCancelRestocksExactlyOnce(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $order = $this->placeOrder($ids['variant'], 2, 10.0);
        $service = $this->get(OrderService::class);
        $this->assertSame(3, $this->stockOf($ids['variant']));

        $service->cancel($order['id']);
        $this->assertSame('cancelled', $this->orderRow($order['id'])['status']);
        $this->assertEquals(0, $this->orderRow($order['id'])['stock_reserved']);
        $this->assertSame(5, $this->stockOf($ids['variant']));

        $this->assertThrows(RuntimeException::class, fn () => $service->cancel($order['id']), 'can be cancelled');
        $this->assertSame(5, $this->stockOf($ids['variant']), 'a second cancel gives nothing back');
    }

    public function testProviderRefundUnsupportedLeavesTheOrderUnchanged(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $order = $this->placeOrder($ids['variant'], 1, 10.0); // bank transfer: refunds stay manual
        $service = $this->get(OrderService::class);
        $service->markPaid($order['id']);

        $this->assertFalse($service->canRefundAtProvider($this->orderRow($order['id'])));
        $this->assertThrows(RuntimeException::class, fn () => $service->refund($order['id'], true, true), 'manually');

        $row = $this->orderRow($order['id']);
        $this->assertSame('paid', $row['payment_status']);
        $this->assertSame('paid', $row['status']);
        $this->assertSame(4, $this->stockOf($ids['variant']), 'not restocked');
    }

    public function testRefundWithRestockAfterPayment(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $order = $this->placeOrder($ids['variant'], 1, 10.0);
        $service = $this->get(OrderService::class);

        $service->markPaid($order['id']);
        $this->assertSame('paid', $this->orderRow($order['id'])['payment_status']);
        $this->assertThrows(RuntimeException::class, fn () => $service->cancel($order['id']), 'Refund');

        $service->refund($order['id'], true);
        $this->assertSame('refunded', $this->orderRow($order['id'])['status']);
        $this->assertSame(5, $this->stockOf($ids['variant']));
    }
}
