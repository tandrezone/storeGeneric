<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use Tests\HttpTestCase;

final class AdminSaleHttpTest extends HttpTestCase
{
    public function testAdminSetsASaleAndTheStorefrontShowsIt(): void
    {
        $this->loginAdmin('manager');
        $ids = $this->makeProduct($this->sku(), 20.0, 5, 'Sale Widget');

        $this->post('/admin/products', [
            'action' => 'update_variant', 'id' => $ids['product'], 'variant_id' => $ids['variant'],
            'sku' => $this->sku('S'), 'label' => '1', 'unit' => 'pc', 'price' => '20.00',
            'sale_price' => '15.00', 'sale_starts_at' => '', 'sale_ends_at' => date('Y-m-d\TH:i', strtotime('+3 days')), 'stock' => '5', 'is_active' => '1',
        ]);
        $row = $this->pdo()->query('SELECT sale_price, sale_ends_at FROM product_variants WHERE id = ' . (int) $ids['variant'])->fetch();
        $this->assertEquals(15.0, $row['sale_price']);
        $this->assertNotNull($row['sale_ends_at']);

        $home = $this->html($this->get_('/'));
        $this->assertStringContainsString('sale-badge', $home);

        $bare = $this->get_('/product/' . $ids['product']);
        $page = $this->html($this->get_(parse_url($bare->getHeaderLine('Location'), PHP_URL_PATH)));
        $this->assertStringContainsString('Sale ends', $page);
        $this->assertStringContainsString('was ', $page);
    }

    public function testSalePriceMustBeLowerThanRegular(): void
    {
        $this->loginAdmin('manager');
        $ids = $this->makeProduct($this->sku(), 20.0, 5);
        $this->post('/admin/products', [
            'action' => 'update_variant', 'id' => $ids['product'], 'variant_id' => $ids['variant'],
            'sku' => $this->sku('S'), 'label' => '1', 'unit' => 'pc', 'price' => '20.00', 'sale_price' => '25.00', 'stock' => '5', 'is_active' => '1',
        ]);
        $this->assertNull($this->pdo()->query('SELECT sale_price FROM product_variants WHERE id = ' . (int) $ids['variant'])->fetchColumn() ?: null);
    }
}
