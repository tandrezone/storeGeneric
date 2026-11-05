<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Repository\OrderNoteRepository;
use App\Service\MarketplaceOrderImporter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Tests\HttpTestCase;

final class MarketplaceOrdersHttpTest extends HttpTestCase
{
    private string $key = '';

    public function setUp(): void
    {
        parent::setUp();
        $this->key = $this->get(MarketplaceOrderImporter::class)->generateKey();
    }

    public function tearDown(): void
    {
        $this->get(MarketplaceOrderImporter::class)->revokeKey();
        parent::tearDown();
    }

    /** @param array<string, mixed>|string $payload */
    private function send(array|string $payload, ?string $key = null): \Psr\Http\Message\ResponseInterface
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'http://localhost/api/marketplace/orders', ['REMOTE_ADDR' => '203.0.113.5'])
            ->withBody($factory->createStream(is_array($payload) ? json_encode($payload) : $payload))
            ->withHeader('Content-Type', 'application/json');
        if (($key ?? $this->key) !== '') {
            $request = $request->withHeader('Authorization', 'Bearer ' . ($key ?? $this->key));
        }
        $kernel = \App\Kernel::boot(dirname(__DIR__, 3));
        $kernel->container()->set(\Psr\Log\LoggerInterface::class, new \Psr\Log\NullLogger());

        return $kernel->handle($request);
    }

    /** @return array<string, mixed> */
    private function payload(string $sku, int $qty = 2, float $price = 4.5, string $external = ''): array
    {
        return [
            'source' => 'glovo', 'external_id' => $external ?: 'G-' . bin2hex(random_bytes(4)),
            'customer' => ['name' => 'Ana Costa', 'phone' => '+351900000000', 'address1' => 'Rua A 1', 'city' => 'Porto', 'postal_code' => '4000-001', 'country' => 'Portugal'],
            'items' => [['sku' => $sku, 'quantity' => $qty, 'unit_price' => $price]],
            'delivery_fee' => 2.5, 'discount' => 1.0, 'note' => 'Ring twice',
        ];
    }

    public function testImportCreatesAPaidOrderUsingMarketplacePricesAndTakesStock(): void
    {
        $sku = $this->sku();
        $ids = $this->makeProduct($sku, 10.0, 5, 'Marketplace thing'); // catalogue price 10, marketplace sold at 4.50

        $response = $this->send($this->payload($sku, 2, 4.5));
        $this->assertStatus(201, $response);
        $result = json_decode($this->html($response), true);

        $order = $this->pdo()->query("SELECT * FROM orders WHERE order_number = " . $this->pdo()->quote($result['order_number']))->fetch();
        $this->assertSame('glovo', $order['source']);
        $this->assertSame('paid', $order['status']);
        $this->assertSame('paid', $order['payment_status']);
        $this->assertEquals(9.0, $order['subtotal']);
        $this->assertEquals(10.5, $order['total'], '9.00 + 2.50 delivery − 1.00 discount');
        $this->assertSame('Ana Costa', $order['ship_name']);
        $this->assertSame(3, $this->stockOf($ids['variant']));
        $this->assertEquals(4.5, $this->pdo()->query('SELECT unit_price FROM order_items WHERE order_id = ' . (int) $order['id'])->fetchColumn());
        $this->assertStringContainsString('Ring twice', $this->get(OrderNoteRepository::class)->forOrder((int) $order['id'])[0]['body']);
    }

    public function testRetryIsIdempotent(): void
    {
        $sku = $this->sku();
        $ids = $this->makeProduct($sku, 10.0, 5);
        $payload = $this->payload($sku, 1, 4.0, 'G-SAME');
        $first = json_decode($this->html($this->send($payload)), true);
        $second = $this->send($payload);
        $this->assertStatus(200, $second);
        $again = json_decode($this->html($second), true);
        $this->assertSame($first['order_number'], $again['order_number']);
        $this->assertTrue($again['duplicate']);
        $this->assertSame(4, $this->stockOf($ids['variant']), 'stock taken once');
    }

    public function testAuthentication(): void
    {
        $sku = $this->sku();
        $this->makeProduct($sku, 10.0, 5);
        $this->assertStatus(401, $this->send($this->payload($sku), ''));
        $this->assertStatus(401, $this->send($this->payload($sku), 'mk_wrong'));
        $old = $this->key;
        $this->key = $this->get(MarketplaceOrderImporter::class)->generateKey();
        $this->assertStatus(401, $this->send($this->payload($sku), $old), 'a replaced key stops working');
        $this->get(MarketplaceOrderImporter::class)->revokeKey();
        $this->assertStatus(401, $this->send($this->payload($sku)), 'revoked');
    }

    public function testUnknownSkuRejectsTheWholeOrderAndKeepsStock(): void
    {
        $sku = $this->sku();
        $ids = $this->makeProduct($sku, 10.0, 5);
        $payload = $this->payload($sku, 1, 4.0);
        $payload['items'][] = ['sku' => 'NOPE-123', 'quantity' => 1, 'unit_price' => 1.0];
        $response = $this->send($payload);
        $this->assertStatus(422, $response);
        $this->assertStringContainsString('NOPE-123', $this->html($response));
        $this->assertSame(5, $this->stockOf($ids['variant']));
        $this->assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM orders WHERE external_id = " . $this->pdo()->quote($payload['external_id']))->fetchColumn());
    }

    public function testNotEnoughStockStillImportsWithANote(): void
    {
        $sku = $this->sku();
        $ids = $this->makeProduct($sku, 10.0, 1);
        $result = json_decode($this->html($this->send($this->payload($sku, 3, 4.0))), true);
        $this->assertSame(0, $this->stockOf($ids['variant']));
        $orderId = (int) $this->pdo()->query("SELECT id FROM orders WHERE order_number = " . $this->pdo()->quote($result['order_number']))->fetchColumn();
        $bodies = implode("\n", array_column($this->get(OrderNoteRepository::class)->forOrder($orderId), 'body'));
        $this->assertStringContainsString('Not enough stock', $bodies);
    }

    public function testValidationListsEveryProblem(): void
    {
        $response = $this->send(['source' => 'X', 'items' => [['sku' => '', 'quantity' => 0, 'unit_price' => -1]], 'currency' => 'USD', 'delivery_fee' => 'abc']);
        $this->assertStatus(422, $response);
        $error = json_decode($this->html($response), true)['error'];
        foreach (['source', 'external_id', 'currency', 'items[0]', 'delivery_fee'] as $field) {
            $this->assertStringContainsString($field, $error);
        }
        $this->assertStatus(422, $this->send('not json'));
    }

    public function testRefundingAnImportedOrderRestocks(): void
    {
        $sku = $this->sku();
        $ids = $this->makeProduct($sku, 10.0, 5);
        $result = json_decode($this->html($this->send($this->payload($sku, 2, 4.0))), true);
        $orderId = (int) $this->pdo()->query("SELECT id FROM orders WHERE order_number = " . $this->pdo()->quote($result['order_number']))->fetchColumn();
        $this->get(\App\Service\OrderService::class)->refund($orderId, true);
        $this->assertSame(5, $this->stockOf($ids['variant']));
    }

    public function testAdminSeesTheSourceAndOwnerCanManageTheKey(): void
    {
        $sku = $this->sku();
        $this->makeProduct($sku, 10.0, 5);
        $result = json_decode($this->html($this->send($this->payload($sku))), true);
        $orderId = (int) $this->pdo()->query("SELECT id FROM orders WHERE order_number = " . $this->pdo()->quote($result['order_number']))->fetchColumn();

        $this->loginAdmin('owner');
        $this->assertStringContainsString('Imported from Glovo', $this->html($this->get_('/admin/orders/' . $orderId)));

        $this->post('/admin/settings', ['action' => 'marketplace_key']);
        $page = $this->html($this->get_('/admin/settings'));
        $this->assertTrue(preg_match('/mk_[a-f0-9]{48}/', $page, $m) === 1, 'new key shown once');
        $this->assertStringNotContainsString($m[0], $this->html($this->get_('/admin/settings')), 'and not again');
        $this->assertTrue($this->get(MarketplaceOrderImporter::class)->keyIsValid($m[0]));
    }
}
