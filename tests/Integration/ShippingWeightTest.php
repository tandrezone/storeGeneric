<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Service\CheckoutService;
use Tests\IntegrationTestCase;

final class ShippingWeightTest extends IntegrationTestCase
{
    private function customer(): array
    {
        return ['name' => 'A B', 'email' => 'a@example.com', 'phone' => '', 'address1' => '1 St', 'address2' => '', 'city' => 'Lisbon', 'state' => '', 'postal_code' => '1000', 'country' => 'Portugal'];
    }

    public function testOrderStoresItsWeightAndPaysTheWeightSurcharge(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 10);
        $this->pdo()->exec('UPDATE product_variants SET weight_grams = 750 WHERE id = ' . (int) $ids['variant']);
        $this->pdo()->exec("UPDATE shipping_methods SET cost = 3.00, cost_per_kg = 2.00, free_over = NULL WHERE code = 'standard'");

        $items = [['variant_id' => $ids['variant'], 'quantity' => 2, 'price' => 10.0, 'product_name' => 'Test product', 'weight_grams' => 750]];
        $order = $this->get(CheckoutService::class)->placeOrder($this->customer(), $items, 'standard', 'bank_transfer');

        $row = $this->orderRow($order['id']);
        $this->assertSame(1500, (int) $row['weight_grams']);
        $this->assertEquals(6.0, $row['shipping_cost'], '3.00 + 1.5 kg * 2.00');
        $this->assertEquals(26.0, $row['total']);
    }

    public function testMethodOutsideItsWeightRangeIsRefused(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 10);
        $this->pdo()->exec('UPDATE product_variants SET weight_grams = 5000 WHERE id = ' . (int) $ids['variant']);
        $this->pdo()->exec("UPDATE shipping_methods SET cost_per_kg = 0, free_over = NULL, max_weight_grams = 2000 WHERE code = 'standard'");

        $items = [['variant_id' => $ids['variant'], 'quantity' => 1, 'price' => 10.0, 'product_name' => 'Test product', 'weight_grams' => 5000]];
        $this->assertThrows(\RuntimeException::class, fn () => $this->get(CheckoutService::class)->placeOrder($this->customer(), $items, 'standard', 'bank_transfer'), 'order weight');

        $this->pdo()->exec("UPDATE shipping_methods SET max_weight_grams = NULL WHERE code = 'standard'");
    }
}
