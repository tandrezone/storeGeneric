<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Service\OrderService;
use Tests\HttpTestCase;

final class BulkOrdersHttpTest extends HttpTestCase
{
    private function status(int $id): string
    {
        return (string) $this->pdo()->query("SELECT status FROM orders WHERE id = $id")->fetchColumn();
    }

    private function paidOrder(): array
    {
        $ids = $this->makeProduct($this->sku('K'), 10.0, 20, 'Bulk thing');
        $order = $this->placeOrder($ids['variant'], 2, 10.0);
        $this->get(OrderService::class)->markPaid($order['id']);

        return $order;
    }

    public function testBulkShipAndStatusChangesWithSkipsReported(): void
    {
        $this->loginAdmin('staff');
        $a = $this->paidOrder();
        $b = $this->paidOrder();
        $cancelled = $this->paidOrder();
        $this->get(OrderService::class)->refund($cancelled['id'], true);

        $this->post('/admin/orders/bulk', ['bulk_action' => 'processing', 'ids' => [$a['id'], $b['id'], $cancelled['id']]]);
        $this->assertSame('processing', $this->status($a['id']));
        $this->assertSame('processing', $this->status($b['id']));
        $this->assertSame('refunded', $this->status($cancelled['id']), 'refunded orders are skipped, not forced');
        $flashes = json_encode($this->flashes());
        $this->assertStringContainsString('2 orders updated', $flashes);
        $this->assertStringContainsString($cancelled['order_number'], $flashes);

        $this->post('/admin/orders/bulk', ['bulk_action' => 'ship', 'carrier' => 'CTT', 'ids' => [$a['id'], $b['id']]]);
        $row = $this->pdo()->query("SELECT status, carrier FROM orders WHERE id = {$a['id']}")->fetch();
        $this->assertSame('shipped', $row['status']);
        $this->assertSame('CTT', $row['carrier']);

        $this->post('/admin/orders/bulk', ['bulk_action' => 'completed', 'ids' => [$a['id']]]);
        $this->assertSame('completed', $this->status($a['id']));
    }

    public function testNothingTickedOrUnknownActionChangesNothing(): void
    {
        $this->loginAdmin('staff');
        $a = $this->paidOrder();
        $this->post('/admin/orders/bulk', ['bulk_action' => 'processing']);
        $this->post('/admin/orders/bulk', ['bulk_action' => 'delete-everything', 'ids' => [$a['id']]]);
        $this->assertSame('paid', $this->status($a['id']));
    }

    public function testPackingSlipsListSkusQuantitiesAndAddress(): void
    {
        $this->loginAdmin('staff');
        $sku = $this->sku('P');
        $ids = $this->makeProduct($sku, 10.0, 20, 'Slip thing');
        $order = $this->placeOrder($ids['variant'], 3, 10.0);

        $page = $this->html($this->get_('/admin/orders/packing-slips', ['ids' => [$order['id']]]));
        $this->assertStringContainsString($order['order_number'], $page);
        $this->assertStringContainsString($sku, $page);
        $this->assertStringContainsString('Slip thing', $page);
        $this->assertStringContainsString('<td class="qty">3</td>', $page);
        $this->assertStringContainsString('Packing slip', $page);
        $this->assertStringNotContainsString('Internal notes', $page, 'staff notes never print');

        $this->assertRedirectsTo('/admin/orders', $this->get_('/admin/orders/packing-slips', ['ids' => [999999]]));
    }

    public function testListShowsTheBulkControlsAndNeedsALogin(): void
    {
        $this->assertRedirectsTo('/admin/login', $this->post('/admin/orders/bulk', ['bulk_action' => 'processing', 'ids' => [1]]));
        $this->loginAdmin('staff');
        $this->paidOrder();
        $page = $this->html($this->get_('/admin/orders'));
        $this->assertStringContainsString('data-select-all="orders"', $page);
        $this->assertStringContainsString('name="ids[]"', $page);
        $this->assertStringContainsString('Print packing slips', $page);
    }
}
