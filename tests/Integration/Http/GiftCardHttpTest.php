<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Service\GiftCardService;
use Tests\HttpTestCase;

final class GiftCardHttpTest extends HttpTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        putenv('PAYMENT_COD_ENABLED=true'); // every request boots a fresh container that reads the environment
    }

    public function tearDown(): void
    {
        putenv('PAYMENT_COD_ENABLED');
        parent::tearDown();
    }

    private function cartWith(int $variantId, int $qty = 2): void
    {
        $this->pdo()->exec('DELETE FROM carts');
        $this->pdo()->exec("UPDATE shipping_methods SET cost = 0, cost_per_kg = 0, free_over = NULL, min_weight_grams = NULL, max_weight_grams = NULL WHERE code = 'standard'");
        $this->request('POST', '/api/cart', ['action' => 'add', 'variant_id' => $variantId, 'quantity' => $qty]);
    }

    /** @return array<string, string> */
    private function form(array $extra = []): array
    {
        return $extra + ['name' => 'Ana', 'email' => 'ana@example.com', 'phone' => '', 'address1' => 'Rua 1', 'address2' => '', 'city' => 'Porto', 'state' => '', 'postal_code' => '4000', 'country' => 'Portugal', 'shipping_method' => 'standard', 'payment_method' => 'cod'];
    }

    public function testApplyShowsTheCardAndTheReducedTotalThenPlacesTheOrder(): void
    {
        $ids = $this->makeProduct($this->sku('H'), 10.0, 20, 'Gifted thing');
        $this->cartWith($ids['variant']); // 20.00
        $card = $this->get(GiftCardService::class)->issue(5.0, null, null, null, null, 'test');

        $bad = $this->post('/checkout', $this->form(['gift_action' => 'apply', 'gift_code' => 'GC-NOPE-NOPE-NOPE']));
        $this->assertStatus(422, $bad);
        $this->assertStringContainsString('doesn&#039;t exist', $this->html($bad));

        $applied = $this->html($this->post('/checkout', $this->form(['gift_action' => 'apply', 'gift_code' => strtolower($card['code'])])));
        $this->assertStringContainsString('checkout-gift-row', $applied);
        $this->assertStringContainsString($card['code'], $applied);
        $this->assertStringContainsString('id="checkout-total">€15.00', $applied);

        $quote = json_decode($this->html($this->get_('/api/checkout/quote', ['country' => 'Portugal', 'shipping_method' => 'standard'])), true);
        $this->assertEquals(15.0, $quote['total']);
        $this->assertEquals(5.0, $quote['gift_card_amount']);

        $placed = $this->post('/checkout', $this->form());
        $this->assertTrue(in_array($placed->getStatusCode(), [302, 303], true), 'redirect after placing: ' . $placed->getStatusCode());
        $row = $this->pdo()->query("SELECT total, gift_card_amount, gift_card_code, status FROM orders WHERE email = 'ana@example.com' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertEquals(15.0, $row['total']);
        $this->assertEquals(5.0, $row['gift_card_amount']);
        $this->assertSame($card['code'], $row['gift_card_code']);
        $this->assertEquals(0.0, $this->pdo()->query('SELECT balance FROM gift_cards WHERE id = ' . (int) $card['id'])->fetchColumn());
    }

    public function testFullyCoveredOrderIsPaidAtOnce(): void
    {
        $ids = $this->makeProduct($this->sku('F'), 10.0, 20);
        $this->cartWith($ids['variant'], 1); // 10.00
        $card = $this->get(GiftCardService::class)->issue(50.0, null, null, null, null, 'test');
        $this->post('/checkout', $this->form(['gift_action' => 'apply', 'gift_code' => $card['code']]));
        $this->post('/checkout', $this->form());

        $row = $this->pdo()->query("SELECT total, status, payment_status FROM orders WHERE email = 'ana@example.com' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertEquals(0.0, $row['total']);
        $this->assertSame('paid', $row['payment_status']);
        $this->assertEquals(40.0, $this->pdo()->query('SELECT balance FROM gift_cards WHERE id = ' . (int) $card['id'])->fetchColumn());
    }

    public function testRemoveGiftCard(): void
    {
        $ids = $this->makeProduct($this->sku('R'), 10.0, 20);
        $this->cartWith($ids['variant'], 1);
        $card = $this->get(GiftCardService::class)->issue(5.0, null, null, null, null, 'test');
        $this->post('/checkout', $this->form(['gift_action' => 'apply', 'gift_code' => $card['code']]));
        $page = $this->html($this->post('/checkout', $this->form(['gift_action' => 'remove'])));
        $this->assertStringContainsString('id="checkout-total">€10.00', $page);
        $this->assertStringNotContainsString($card['code'], $page);
    }

    public function testAdminCreatesACardAndEmailsIt(): void
    {
        $this->loginAdmin('manager');
        $response = $this->post('/admin/giftcards', ['action' => 'create', 'amount' => '25', 'recipient_email' => 'friend@example.com', 'message' => 'Happy birthday', 'send_email' => '1']);
        $this->assertTrue(in_array($response->getStatusCode(), [302, 303], true));
        $card = $this->pdo()->query('SELECT * FROM gift_cards ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertEquals(25.0, $card['balance']);
        $this->assertSame('friend@example.com', $card['recipient_email']);

        $log = (string) file_get_contents(dirname(__DIR__, 3) . '/var/log/mail-' . date('Y-m-d') . '.log');
        $this->assertStringContainsString($card['code'], $log);
        $this->assertStringContainsString('Happy birthday', $log);

        $page = $this->html($this->get_('/admin/giftcards', ['card' => $card['id']]));
        $this->assertStringContainsString($card['code'], $page);
        $this->assertStringContainsString('issue', $page);

        $this->post('/admin/giftcards', ['action' => 'adjust', 'id' => $card['id'], 'delta' => '-5', 'note' => 'goodwill fix']);
        $this->assertEquals(20.0, $this->pdo()->query('SELECT balance FROM gift_cards WHERE id = ' . (int) $card['id'])->fetchColumn());
        $this->assertStatus(422, $this->post('/admin/giftcards', ['action' => 'create', 'amount' => '0']));
    }

    public function testOrderPageAndInvoiceShowTheGiftCardLine(): void
    {
        $this->loginAdmin('manager');
        $ids = $this->makeProduct($this->sku('I'), 10.0, 20);
        $this->cartWith($ids['variant'], 2);
        $card = $this->get(GiftCardService::class)->issue(5.0, null, null, null, null, 'test');
        $this->post('/checkout', $this->form(['gift_action' => 'apply', 'gift_code' => $card['code']]));
        $this->post('/checkout', $this->form());
        $order = $this->pdo()->query("SELECT id FROM orders WHERE email = 'ana@example.com' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $this->get(\App\Service\OrderService::class)->markPaid((int) $order);

        $page = $this->html($this->get_('/admin/orders/' . $order));
        $this->assertStringContainsString('Gift card (' . $card['code'] . ')', $page);
        $pdf = $this->get_('/admin/orders/' . $order . '/invoice');
        $this->assertStatus(200, $pdf);
        $this->assertTrue(str_starts_with($this->html($pdf), '%PDF'));
    }
}
