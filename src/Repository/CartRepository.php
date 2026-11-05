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

    /** Remembers whose cart it is (signed-in customers), for the abandoned-cart reminder. */
    public function attachCustomer(int $cartId, int $customerId): void
    {
        // updated_at is left alone: only cart changes count as activity.
        $this->run('UPDATE carts SET customer_id = :c, updated_at = updated_at WHERE id = :id AND (customer_id IS NULL OR customer_id <> :c2)', ['c' => $customerId, 'c2' => $customerId, 'id' => $cartId]);
    }

    /** @return list<array<string, mixed>> carts of customers that sat untouched between $afterHours and $maxDays, not yet reminded, with something in them and no later order */
    public function abandoned(int $afterHours, int $maxDays, int $limit = 200): array
    {
        return $this->all("
            SELECT c.id, c.customer_id, cu.email, cu.name, cu.locale
            FROM carts c JOIN customers cu ON cu.id = c.customer_id
            WHERE c.reminded_at IS NULL AND cu.active = 1 AND cu.deleted_at IS NULL AND cu.email_verified_at IS NOT NULL
              AND c.updated_at < DATE_SUB(NOW(), INTERVAL " . max(1, $afterHours) . " HOUR)
              AND c.updated_at > DATE_SUB(NOW(), INTERVAL " . max(1, $maxDays) . " DAY)
              AND EXISTS (SELECT 1 FROM cart_items ci WHERE ci.cart_id = c.id)
              AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.customer_id = c.customer_id AND o.created_at > c.updated_at)
            ORDER BY c.id LIMIT " . max(1, $limit));
    }

    public function markReminded(int $cartId): void
    {
        $this->run('UPDATE carts SET reminded_at = NOW(), updated_at = updated_at WHERE id = :id', ['id' => $cartId]);
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
                   v.sku, v.label, v.unit, " . VariantRepository::EFFECTIVE_PRICE . " AS price, v.price AS regular_price, v.stock, v.weight_grams,
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
