<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use Tests\HttpTestCase;

final class AdminOrderNotesHttpTest extends HttpTestCase
{
    public function testStaffCanAddAndDeleteANoteFromTheOrderPage(): void
    {
        $this->loginAdmin('staff');
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $order = $this->placeOrder($ids['variant'], 1, 10.0);
        $url = '/admin/orders/' . $order['id'];

        $this->assertRedirectsTo($url, $this->post($url, ['action' => 'add_note', 'note' => 'Customer wants a gift box']));
        $page = $this->html($this->get_($url));
        $this->assertStringContainsString('Customer wants a gift box', $page);
        $this->assertStringContainsString('Internal notes', $page);

        $noteId = (int) $this->pdo()->query('SELECT id FROM order_notes WHERE order_id = ' . (int) $order['id'])->fetchColumn();
        $this->post($url, ['action' => 'delete_note', 'note_id' => $noteId]);
        $this->assertStringNotContainsString('Customer wants a gift box', $this->html($this->get_($url)));
    }

    public function testEmptyNoteIsRefused(): void
    {
        $this->loginAdmin('staff');
        $ids = $this->makeProduct($this->sku(), 10.0, 5);
        $order = $this->placeOrder($ids['variant'], 1, 10.0);
        $this->post('/admin/orders/' . $order['id'], ['action' => 'add_note', 'note' => '   ']);
        $this->assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM order_notes WHERE order_id = ' . (int) $order['id'])->fetchColumn());
    }
}
