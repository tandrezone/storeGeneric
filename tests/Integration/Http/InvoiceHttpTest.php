<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Repository\OrderRepository;
use App\Service\OrderLinks;
use App\Service\OrderService;
use Tests\HttpTestCase;

final class InvoiceHttpTest extends HttpTestCase
{
    private function paid(): array
    {
        $ids = $this->makeProduct($this->sku(), 15.0, 5);
        $order = $this->placeOrder($ids['variant'], 1, 15.0);
        $this->get(OrderService::class)->markPaid($order['id']);

        return $order;
    }

    public function testCustomerDownloadsWithTheSignedLinkOnly(): void
    {
        $order = $this->paid();
        $links = $this->get(OrderLinks::class);
        $query = $links->query($order['order_number']);

        $ok = $this->get_('/order/invoice', $query);
        $this->assertStatus(200, $ok);
        $this->assertSame('application/pdf', $ok->getHeaderLine('Content-Type'));
        $this->assertTrue(str_starts_with($this->html($ok), '%PDF'));

        $this->assertStatus(404, $this->get_('/order/invoice', ['order' => $order['order_number'], 'key' => 'wrong']));
        $this->assertStatus(404, $this->get_('/order/invoice', ['order' => $order['order_number']]));
    }

    public function testUnpaidOrderHasNoInvoiceLink(): void
    {
        $ids = $this->makeProduct($this->sku(), 15.0, 5);
        $order = $this->placeOrder($ids['variant'], 1, 15.0);
        $query = $this->get(OrderLinks::class)->query($order['order_number']);
        $this->assertStatus(404, $this->get_('/order/invoice', $query));
        $this->assertStringNotContainsString('Download invoice', $this->html($this->get_('/order/track', $query)));
    }

    public function testPaidOrderPageOffersTheDownload(): void
    {
        $order = $this->paid();
        $page = $this->html($this->get_('/order/track', $this->get(OrderLinks::class)->query($order['order_number'])));
        $this->assertStringContainsString('Download invoice (PDF)', $page);
    }

    public function testAdminDownload(): void
    {
        $this->loginAdmin('staff');
        $order = $this->paid();
        $response = $this->get_('/admin/orders/' . $order['id'] . '/invoice');
        $this->assertStatus(200, $response);
        $this->assertStringContainsString('INV-', $response->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('Invoice (PDF)', $this->html($this->get_('/admin/orders/' . $order['id'])));
    }
}
