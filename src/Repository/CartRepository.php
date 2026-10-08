<?php

declare(strict_types=1);

namespace App\Repository;

/** Carts are keyed by the visitor's session id; one cart per session. */
final class CartRepository extends Repository
{
    /** SQL condition (aliases v, p): the line can still be bought. */
    private const AVAILABLE = "(v.is_active = 1 AND v.price > 0 AND p.is_active = 1 AND p.import_status = 'approved')";

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

    /**
     * Lines joined with variant and product details. Lines whose variant or
     * product is hidden (inactive, not approved) or unpriced are left out;
     * removeUnavailable() deletes them. product_name is in $locale ('' = the
     * store's own language); base_name is the original (URL slugs).
     *
     * @return list<array<string, mixed>>
     */
    public function items(int $cartId, string $locale = ''): array
    {
        return $this->all("
            SELECT ci.variant_id, ci.quantity,
                   v.sku, v.label, v.unit, v.price, v.stock,
                   p.id AS product_id, p.name AS base_name,
                   COALESCE(NULLIF(pt.name, ''), p.name) AS product_name, p.image_path
            FROM cart_items ci
            JOIN product_variants v ON v.id = ci.variant_id
            JOIN products p ON p.id = v.product_id
            LEFT JOIN product_translations pt ON pt.product_id = p.id AND pt.locale = :loc
            WHERE ci.cart_id = :cart_id AND " . self::AVAILABLE . "
            ORDER BY ci.id ASC
        ", ['cart_id' => $cartId, 'loc' => $locale]);
    }

    /** @return list<string> names of the products whose lines were removed */
    public function removeUnavailable(int $cartId): array
    {
        $names = array_column($this->all('
            SELECT p.name
            FROM cart_items ci
            JOIN product_variants v ON v.id = ci.variant_id
            JOIN products p ON p.id = v.product_id
            WHERE ci.cart_id = :cart_id AND NOT ' . self::AVAILABLE, ['cart_id' => $cartId]), 'name');
        if ($names !== []) {
            $this->run('
                DELETE ci FROM cart_items ci
                JOIN product_variants v ON v.id = ci.variant_id
                JOIN products p ON p.id = v.product_id
                WHERE ci.cart_id = :cart_id AND NOT ' . self::AVAILABLE, ['cart_id' => $cartId]);
        }

        return array_values(array_unique(array_map('strval', $names)));
    }

    public function quantity(int $cartId, int $variantId): int
    {
        return (int) $this->value(
            'SELECT quantity FROM cart_items WHERE cart_id = :cart_id AND variant_id = :variant_id',
            ['cart_id' => $cartId, 'variant_id' => $variantId]
        );
    }

    public function clear(int $cartId): void
    {
        $this->run('DELETE FROM cart_items WHERE cart_id = :cart_id', ['cart_id' => $cartId]);
    }
}
