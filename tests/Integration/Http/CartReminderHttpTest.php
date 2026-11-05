<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use App\Repository\SettingRepository;
use App\Service\CartReminderService;
use Tests\HttpTestCase;

final class CartReminderHttpTest extends HttpTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->pdo()->exec('DELETE FROM mail_queue');
        $this->pdo()->exec('DELETE FROM carts');
    }

    public function tearDown(): void
    {
        $this->get(SettingRepository::class)->set('abandoned_cart_enabled', null);
        parent::tearDown();
    }

    private function age(int $hours): void
    {
        $this->pdo()->exec("UPDATE carts SET updated_at = NOW() - INTERVAL $hours HOUR");
    }

    private function customerWithCart(int $variantId): array
    {
        $customer = $this->loginCustomer();
        $this->pdo()->prepare('UPDATE customers SET email_verified_at = NOW() WHERE id = ?')->execute([$customer['id']]);
        $this->request('POST', '/api/cart', ['action' => 'add', 'variant_id' => $variantId, 'quantity' => 2], [], ['X-CSRF-Token' => $_SESSION['csrf_token'] ?? '']);

        return $customer;
    }

    public function testReminderIsSentOnceToTheSignedInCartOwner(): void
    {
        $ids = $this->makeProduct($this->sku(), 12.0, 5, 'Forgotten thing');
        $customer = $this->customerWithCart($ids['variant']);
        $this->assertSame($customer['id'], (int) $this->pdo()->query('SELECT customer_id FROM carts')->fetchColumn(), 'the cart knows its owner');

        $service = $this->get(CartReminderService::class);
        $this->assertSame(0, $service->remind(), 'too fresh');
        $this->age(30);
        $this->assertSame(1, $service->remind());
        $this->assertSame(0, $service->remind(), 'once per cart');

        $mail = $this->pdo()->query('SELECT to_email, subject, body_html FROM mail_queue')->fetch();
        $this->assertSame($customer['email'], $mail['to_email']);
        $this->assertStringContainsString('Forgotten thing', $mail['body_html']);
        $this->assertStringContainsString('/cart/recover?cart=', $mail['body_html']);
    }

    public function testNoReminderWithoutAccountOrAfterAnOrderOrForUnverifiedCustomers(): void
    {
        $ids = $this->makeProduct($this->sku(), 12.0, 5);
        $service = $this->get(CartReminderService::class);

        // visitor with a cart: never contacted (no email known)
        $this->request('POST', '/api/cart', ['action' => 'add', 'variant_id' => $ids['variant'], 'quantity' => 1], [], ['X-CSRF-Token' => $_SESSION['csrf_token'] ?? '']);
        $this->age(30);
        $this->assertSame(0, $service->remind());

        // unverified account
        $_SESSION = [];
        $customer = $this->loginCustomer();
        $this->request('POST', '/api/cart', ['action' => 'add', 'variant_id' => $ids['variant'], 'quantity' => 1], [], ['X-CSRF-Token' => $_SESSION['csrf_token'] ?? '']);
        $this->age(30);
        $this->assertSame(0, $service->remind(), 'email not verified');

        // verified but ordered after the cart was last touched
        $this->pdo()->prepare('UPDATE customers SET email_verified_at = NOW() WHERE id = ?')->execute([$customer['id']]);
        $order = $this->placeOrder($ids['variant'], 1, 12.0);
        $this->pdo()->prepare('UPDATE orders SET customer_id = ? WHERE id = ?')->execute([$customer['id'], $order['id']]);
        $this->assertSame(0, $service->remind());
    }

    public function testRecoveryLinkRestoresTheItemsAndRejectsForgeries(): void
    {
        $ids = $this->makeProduct($this->sku(), 12.0, 5, 'Recovered thing');
        $this->customerWithCart($ids['variant']);
        $cartId = (int) $this->pdo()->query('SELECT id FROM carts')->fetchColumn();
        $url = parse_url($this->get(CartReminderService::class)->recoverUrl($cartId));
        parse_str($url['query'], $query);

        // The original browser keeps its cart; this request is a different browser (in the CLI the session id is a constant,
        // so the first cart is moved aside).
        $this->pdo()->exec("UPDATE carts SET session_id = 'browser-a' WHERE session_id = 'cli'");
        $_SESSION = [];
        $this->assertStringNotContainsString('Recovered thing', $this->html($this->get_('/cart')));
        $this->assertRedirectsTo('/cart', $this->get_('/cart/recover', $query));
        $this->assertStringContainsString('Recovered thing', $this->html($this->get_('/cart')));

        $this->pdo()->exec("DELETE FROM carts WHERE session_id = 'cli'"); // a third browser
        $this->get_('/cart/recover', ['cart' => $query['cart'], 'sig' => 'forged']);
        $this->assertStringNotContainsString('Recovered thing', $this->html($this->get_('/cart')));
    }

    public function testCommandIsInertUntilSwitchedOn(): void
    {
        $service = $this->get(CartReminderService::class);
        $this->assertFalse($service->enabled());
        $this->get(SettingRepository::class)->set('abandoned_cart_enabled', '1');
        $this->assertTrue($service->enabled());
        $this->assertSame(24, $service->afterHours());
    }
}
