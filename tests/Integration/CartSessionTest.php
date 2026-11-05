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
