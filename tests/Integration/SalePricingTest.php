<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\VariantRepository;
use Tests\IntegrationTestCase;

final class SalePricingTest extends IntegrationTestCase
{
    private function setSale(int $variant, ?string $price, ?string $from = null, ?string $until = null): void
    {
        $this->pdo()->prepare('UPDATE product_variants SET sale_price = ?, sale_starts_at = ?, sale_ends_at = ? WHERE id = ?')->execute([$price, $from, $until, $variant]);
    }

    public function testRunningSaleLowersTheEffectivePriceAndExpiredOrFutureSalesDoNot(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $repo = $this->get(VariantRepository::class);

        $this->assertEquals(10.0, $repo->findAvailable($ids['variant'])['price']);

        $this->setSale($ids['variant'], '7.50');
        $row = $repo->findAvailable($ids['variant']);
        $this->assertEquals(7.5, $row['price']);
        $this->assertEquals(10.0, $row['regular_price']);

        $this->setSale($ids['variant'], '7.50', date('Y-m-d H:i:s', strtotime('+1 day')));
        $this->assertEquals(10.0, $repo->findAvailable($ids['variant'])['price'], 'not started');

        $this->setSale($ids['variant'], '7.50', date('Y-m-d H:i:s', strtotime('-2 days')), date('Y-m-d H:i:s', strtotime('-1 day')));
        $this->assertEquals(10.0, $repo->findAvailable($ids['variant'])['price'], 'ended');

        $this->setSale($ids['variant'], '12.00');
        $this->assertEquals(10.0, $repo->findAvailable($ids['variant'])['price'], 'a "sale" above the regular price is ignored');
    }

    public function testCheckoutChargesTheSalePrice(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $this->setSale($ids['variant'], '6.00');
        $order = $this->placeOrder($ids['variant'], 2, 6.0);
        $line = $this->pdo()->query('SELECT unit_price, line_total FROM order_items WHERE order_id = ' . (int) $order['id'])->fetch();
        $this->assertEquals(6.0, $line['unit_price']);
        $this->assertEquals(12.0, $line['line_total']);
    }

    public function testListingMarksProductsOnSale(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $row = fn () => $this->pdo()->query('
            SELECT MAX(v.price > 0 AND ' . VariantRepository::EFFECTIVE_PRICE . ' < v.price) AS on_sale
            FROM product_variants v WHERE v.product_id = ' . (int) $ids['product'])->fetch();
        $this->assertEquals(0, $row()['on_sale']);
        $this->setSale($ids['variant'], '5.00');
        $this->assertEquals(1, $row()['on_sale']);
    }

    public function testPhpTwinAgreesWithTheSql(): void
    {
        $base = ['price' => '10.00', 'sale_price' => '5.00', 'sale_starts_at' => null, 'sale_ends_at' => null];
        $this->assertTrue(VariantRepository::onSale($base));
        $this->assertFalse(VariantRepository::onSale(['sale_price' => null] + $base));
        $this->assertFalse(VariantRepository::onSale(['sale_price' => '10.00'] + $base));
        $this->assertFalse(VariantRepository::onSale(['sale_ends_at' => '2000-01-01 00:00:00'] + $base));
        $this->assertFalse(VariantRepository::onSale(['sale_starts_at' => '2999-01-01 00:00:00'] + $base));
    }

    public function testImportersLeaveARunningSaleAlone(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $this->setSale($ids['variant'], '4.00');
        $repo = $this->get(VariantRepository::class);
        $repo->update($ids['variant'], $ids['product'], ['sku' => $this->sku('U'), 'label' => '1', 'unit' => 'pc', 'price' => 11.0, 'stock' => 3, 'is_active' => 1]);
        $row = $this->pdo()->query('SELECT price, sale_price FROM product_variants WHERE id = ' . (int) $ids['variant'])->fetch();
        $this->assertEquals(11.0, $row['price']);
        $this->assertEquals(4.0, $row['sale_price']);
    }
}
