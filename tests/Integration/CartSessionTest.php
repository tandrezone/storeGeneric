<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\CartRepository;
use Tests\IntegrationTestCase;

/**
 * Signing in or out gives the session a new id (session fixation); the cart,
 * keyed by the session id, has to follow it or the customer finds it empty.
 */
final class CartSessionTest extends IntegrationTestCase
{
    public function testTheCartMovesToTheNewSessionId(): void
    {
        $carts = $this->get(CartRepository::class);
        $ids = $this->makeProduct($this->sku('C'), 10.0, 5);
        $old = 'old-' . bin2hex(random_bytes(8));
        $new = 'new-' . bin2hex(random_bytes(8));

        $cart = $carts->idForSession($old);
        $carts->addItem($cart, $ids['variant'], 2);

        $this->assertSame($cart, $carts->adoptPrevious($new, ['gone-' . bin2hex(random_bytes(4)), $old]), 'the newest previous id with a cart wins');
        $this->assertSame($cart, $carts->idForSession($new));
        $this->assertSame(2, $carts->quantity($cart, $ids['variant']));
        $this->assertNull($carts->adoptPrevious($new, [$old]), 'nothing left under the old id');
    }

    public function testASignedOutCustomersCartStaysBehind(): void
    {
        $carts = $this->get(CartRepository::class);
        $ids = $this->makeProduct($this->sku('S'), 10.0, 5);
        $this->pdo()->exec("INSERT INTO customers (email, name, password_hash) VALUES ('" . strtolower($this->sku('c')) . "@example.com', 'C', '')");
        $customer = (int) $this->pdo()->lastInsertId();
        $old = 'old-' . bin2hex(random_bytes(8));
        $cart = $carts->idForSession($old);
        $carts->addItem($cart, $ids['variant'], 1);
        $carts->attachCustomer($cart, $customer);
        $this->pdo()->exec("UPDATE carts SET updated_at = '2026-01-01 10:00:00' WHERE id = {$cart}");

        $this->assertNull($carts->adoptPrevious('anon-' . bin2hex(random_bytes(8)), [$old], null), 'signed out: not taken along');
        $this->assertNull($carts->adoptPrevious('other-' . bin2hex(random_bytes(8)), [$old], $customer + 1000), 'another customer: not taken');
        $this->assertSame($cart, $carts->adoptPrevious('same-' . bin2hex(random_bytes(8)), [$old], $customer), 'the same customer (new session id): taken');
        $this->assertSame('2026-01-01 10:00:00', (string) $this->pdo()->query("SELECT updated_at FROM carts WHERE id = {$cart}")->fetchColumn(), 'moving is not cart activity');
    }

    public function testAnExistingCartUnderTheNewIdIsMergedNotLost(): void
    {
        $carts = $this->get(CartRepository::class);
        $a = $this->makeProduct($this->sku('A'), 10.0, 5);
        $b = $this->makeProduct($this->sku('B'), 10.0, 5);
        $old = 'old-' . bin2hex(random_bytes(8));
        $new = 'new-' . bin2hex(random_bytes(8));

        $oldCart = $carts->idForSession($old);
        $carts->addItem($oldCart, $a['variant'], 1);
        $carts->addItem($oldCart, $b['variant'], 1);
        $newCart = $carts->idForSession($new);
        $carts->addItem($newCart, $a['variant'], 2);

        $this->assertSame($newCart, $carts->adoptPrevious($new, [$old]));
        $this->assertSame(3, $carts->quantity($newCart, $a['variant']));
        $this->assertSame(1, $carts->quantity($newCart, $b['variant']));
        $this->assertFalse($this->pdo()->query('SELECT id FROM carts WHERE id = ' . $oldCart)->fetchColumn(), 'the old cart is gone');
    }
}
