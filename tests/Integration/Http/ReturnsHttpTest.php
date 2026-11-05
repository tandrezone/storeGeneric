<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Repository\OrderRepository;
use App\Service\OrderLinks;
use App\Service\OrderService;
use Tests\HttpTestCase;

final class ReturnsHttpTest extends HttpTestCase
{
    private function shipped(): array
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 10, 'HTTP return');
        $order = $this->placeOrder($ids['variant'], 2, 10.0);
        $service = $this->get(OrderService::class);
        $service->markPaid($order['id']);
        $service->ship($order['id'], 'T', 'UPS');

        return $this->get(OrderRepository::class)->find($order['id']);
    }

    public function testCustomerRequestsAndStaffApproves(): void
    {
        $order = $this->shipped();
        $query = $this->get(OrderLinks::class)->query($order['order_number']);
        $itemId = (int) $order['items'][0]['id'];

        $track = $this->html($this->get_('/order/track', $query));
        $this->assertStringContainsString('Request a return', $track);
        $this->assertStatus(200, $this->get_('/order/return', $query));
        $this->assertStatus(404, $this->get_('/order/return', ['order' => $order['order_number'], 'key' => 'bad']));

        $bad = $this->request('POST', '/order/return', ['qty' => [$itemId => 1], 'reason' => ''], $query);
        $this->assertStatus(422, $bad);

        $ok = $this->request('POST', '/order/return', ['qty' => [$itemId => 1], 'reason' => 'damaged', 'details' => 'Cracked'], $query);
        $this->assertRedirectsTo('/order/track', $ok);
        $this->assertStringContainsString('Requested', $this->html($this->get_('/order/track', $query)));

        $this->loginAdmin('staff');
        $page = $this->html($this->get_('/admin/returns'));
        $this->assertStringContainsString($order['order_number'], $page);
        $id = (int) $this->pdo()->query('SELECT MAX(id) FROM return_requests')->fetchColumn();
        $this->post('/admin/returns', ['id' => $id, 'status' => 'approved', 'response' => 'Send it to the warehouse']);
        $this->assertSame('approved', $this->pdo()->query("SELECT status FROM return_requests WHERE id = $id")->fetchColumn());
        $this->assertStringContainsString('Send it to the warehouse', $this->html($this->get_('/order/track', $query)));
    }

    public function testUnshippedOrderHasNoReturnLink(): void
    {
        $ids = $this->makeProduct($this->sku(), 10.0, 10);
        $order = $this->placeOrder($ids['variant'], 1, 10.0);
        $query = $this->get(OrderLinks::class)->query($order['order_number']);
        $this->assertStringNotContainsString('Request a return', $this->html($this->get_('/order/track', $query)));
        $this->assertStringContainsString("can't be returned", str_replace('&#039;', "'", $this->html($this->get_('/order/return', $query))));
    }
}
