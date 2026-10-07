<?php

declare(strict_types=1);

namespace Tests\Integration;

use RuntimeException;
use Tests\IntegrationTestCase;

final class CheckoutServiceTest extends IntegrationTestCase
{
    public function testPlacesOrderAndReservesStock(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5);

        $order = $this->placeOrder($ids['variant'], 2, 10.0);

        $row = $this->orderRow($order['id']);
        $this->assertSame($order['order_number'], $row['order_number']);
        $this->assertEquals(20.0, $row['subtotal']);
        $this->assertEquals($order['total'], $row['total']);
        $this->assertTrue((float) $row['total'] >= 20.0);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('unpaid', $row['payment_status']);
        $this->assertEquals(1, $row['stock_reserved']);
        $this->assertSame(3, $this->stockOf($ids['variant']));

        $stmt = $this->pdo()->prepare('SELECT quantity, unit_price, product_name FROM order_items WHERE order_id = ?');
        $stmt->execute([$order['id']]);
        $items = $stmt->fetchAll();
        $this->assertCount(1, $items);
        $this->assertEquals(2, $items[0]['quantity']);
        $this->assertEquals(10.0, $items[0]['unit_price']);
    }

    public function testRefusesToOversell(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $ordersBefore = (int) $this->pdo()->query('SELECT COUNT(*) FROM orders')->fetchColumn();

        $this->assertThrows(RuntimeException::class, fn () => $this->placeOrder($ids['variant'], 6, 10.0), 'only 5 left');

        $this->assertSame(5, $this->stockOf($ids['variant']), 'stock untouched');
        $this->assertSame($ordersBefore, (int) $this->pdo()->query('SELECT COUNT(*) FROM orders')->fetchColumn(), 'nothing written (rolled back)');
    }

    public function testSecondBuyerCannotTakeTheSameLastItems(): void
    {
        $ids = $this->makeProduct($this->sku(), 4.5, 3);

        $this->placeOrder($ids['variant'], 2, 4.5);
        $this->assertThrows(RuntimeException::class, fn () => $this->placeOrder($ids['variant'], 2, 4.5), 'Not enough stock');
        $this->placeOrder($ids['variant'], 1, 4.5);

        $this->assertSame(0, $this->stockOf($ids['variant']));
    }

    public function testRejectsChangedPriceAndInactiveVariant(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $this->assertThrows(RuntimeException::class, fn () => $this->placeOrder($ids['variant'], 1, 9.0), 'price');

        $this->pdo()->prepare('UPDATE product_variants SET is_active = 0 WHERE id = ?')->execute([$ids['variant']]);
        $this->assertThrows(RuntimeException::class, fn () => $this->placeOrder($ids['variant'], 1, 10.0), 'no longer available');
        $this->assertSame(5, $this->stockOf($ids['variant']));
    }
}
