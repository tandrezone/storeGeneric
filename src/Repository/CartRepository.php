<?php

declare(strict_types=1);

namespace App\Repository;

/** Carts are keyed by the visitor's session id; one cart per session. */
final class CartRepository extends Repository
{
    /** Id of the session's cart, creating it on first use. */
    public function idForSession(string $sessionId): int
    {
        $id = $this->value('SELECT id FROM carts WHERE session_id = :sid', ['sid' => $sessionId]);
        if ($id !== false) {
            return (int) $id;
        }
        $this->run('INSERT INTO carts (session_id) VALUES (:sid)', ['sid' => $sessionId]);

        return $this->lastId();
    }

    /** Adds a line, or increases its quantity if the variant is already in the cart. */
    public function addItem(int $cartId, int $variantId, int $quantity): void
    {
        $this->run('
            INSERT INTO cart_items (cart_id, variant_id, quantity)
            VALUES (:cart_id, :variant_id, :qty)
            ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
        ', ['cart_id' => $cartId, 'variant_id' => $variantId, 'qty' => $quantity]);
    }

    public function setQuantity(int $cartId, int $variantId, int $quantity): void
    {
        $this->run(
            'UPDATE cart_items SET quantity = :qty WHERE cart_id = :cart_id AND variant_id = :variant_id',
            ['qty' => $quantity, 'cart_id' => $cartId, 'variant_id' => $variantId]
        );
    }

    public function removeItem(int $cartId, int $variantId): void
    {
        $this->run('DELETE FROM cart_items WHERE cart_id = :cart_id AND variant_id = :variant_id', ['cart_id' => $cartId, 'variant_id' => $variantId]);
    }

    /** @return list<array<string, mixed>> lines joined with variant and product details */
    public function items(int $cartId): array
    {
        return $this->all('
            SELECT ci.variant_id, ci.quantity,
                   v.sku, v.label, v.unit, v.price, IF(v.price <= 0, 0, v.stock) AS stock,
                   p.id AS product_id, p.name AS product_name, p.image_path
            FROM cart_items ci
            JOIN product_variants v ON v.id = ci.variant_id
            JOIN products p ON p.id = v.product_id
            WHERE ci.cart_id = :cart_id
            ORDER BY ci.id ASC
        ', ['cart_id' => $cartId]);
    }

    public function clear(int $cartId): void
    {
        $this->run('DELETE FROM cart_items WHERE cart_id = :cart_id', ['cart_id' => $cartId]);
    }
}
