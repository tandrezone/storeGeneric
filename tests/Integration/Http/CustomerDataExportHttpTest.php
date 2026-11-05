<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use Tests\HttpTestCase;

final class CustomerDataExportHttpTest extends HttpTestCase
{
    public function testExportNeedsThePasswordAndNeverContainsSecrets(): void
    {
        $customer = $this->loginCustomer('correct horse battery');
        $ids = $this->makeProduct($this->sku(), 10.0, 5, 'Exported thing');
        $order = $this->placeOrder($ids['variant'], 1, 10.0);
        $this->pdo()->prepare('UPDATE orders SET customer_id = ? WHERE id = ?')->execute([$customer['id'], $order['id']]);

        $wrong = $this->post('/account/profile', ['action' => 'export', 'current_password' => 'nope']);
        $this->assertStatus(422, $wrong);

        $ok = $this->post('/account/profile', ['action' => 'export', 'current_password' => 'correct horse battery']);
        $this->assertStatus(200, $ok);
        $this->assertStringContainsString('attachment', $ok->getHeaderLine('Content-Disposition'));
        $json = (array) json_decode($this->html($ok), true);
        $this->assertSame($customer['email'], $json['profile']['email']);
        $this->assertCount(1, $json['orders']);
        $this->assertSame('Exported thing', $json['orders'][0]['items'][0]['product_name']);
        $this->assertStringNotContainsString('password_hash', $this->html($ok));
    }

    public function testExportRequiresSignIn(): void
    {
        $this->assertRedirectsTo('/account/login', $this->post('/account/profile', ['action' => 'export', 'current_password' => 'x']));
    }

    public function testOtherCustomersOrdersAreNotIncluded(): void
    {
        $this->loginCustomer('correct horse battery');
        $ids = $this->makeProduct($this->sku(), 10.0, 5, 'Someone elses');
        $this->placeOrder($ids['variant'], 1, 10.0); // guest order, not linked
        $ok = $this->post('/account/profile', ['action' => 'export', 'current_password' => 'correct horse battery']);
        $this->assertSame([], ((array) json_decode($this->html($ok), true))['orders']);
    }
}
