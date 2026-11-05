<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\Session;
use App\Repository\CartRepository;
use App\Security\CustomerAuthenticator;
use Throwable;

/** The current visitor's cart (stored in the database, keyed by session). */
final class CartService
{
    private ?int $cartId = null;

    public function __construct(
        private readonly CartRepository $carts,
        private readonly Session $session,
        private readonly ProductTranslations $translations,
        private readonly CustomerAuthenticator $auth,
    ) {
    }

    public function add(int $variantId, int $quantity): void
    {
        $this->carts->addItem($this->id(), $variantId, max(1, $quantity));
        $this->stampCustomer();
    }

    /**
     * Puts the still-buyable lines of another cart into this one (abandoned-cart recovery link).
     *
     * @return int lines copied
     */
    public function copyFrom(int $otherCartId): int
    {
        $copied = 0;
        foreach ($this->carts->items($otherCartId) as $line) {
            $this->add((int) $line['variant_id'], (int) $line['quantity']);
            $copied++;
        }

        return $copied;
    }

    /** Sets a line's quantity; 0 or less removes it. */
    public function update(int $variantId, int $quantity): void
    {
        if ($quantity <= 0) {
            $this->remove($variantId);

            return;
        }
        $this->carts->setQuantity($this->id(), $variantId, $quantity);
        $this->stampCustomer();
    }

    public function remove(int $variantId): void
    {
        $this->carts->removeItem($this->id(), $variantId);
    }

    /** @return list<array<string, mixed>> lines that can still be bought */
    public function items(): array
    {
        return $this->carts->items($this->id(), $this->translations->storefrontLocale());
    }

    /**
     * Deletes lines whose product or option is no longer for sale.
     *
     * @return list<string> names of the products removed (to tell the customer)
     */
    public function removeUnavailable(): array
    {
        return $this->carts->removeUnavailable($this->id());
    }

    /** Quantity of a variant already in the cart (0 if none). */
    public function quantityOf(int $variantId): int
    {
        return $this->carts->quantity($this->id(), $variantId);
    }

    /** @param list<array<string, mixed>>|null $items pass items() to avoid a second query */
    public function total(?array $items = null): float
    {
        $total = 0.0;
        foreach ($items ?? $this->items() as $item) {
            $total += (float) $item['price'] * (int) $item['quantity'];
        }

        return round($total, 2);
    }

    /** Total weight in grams (variants without a weight count as 0). @param list<array<string, mixed>>|null $items */
    public function weight(?array $items = null): int
    {
        $grams = 0;
        foreach ($items ?? $this->items() as $item) {
            $grams += (int) ($item['weight_grams'] ?? 0) * (int) $item['quantity'];
        }

        return $grams;
    }

    /** @param list<array<string, mixed>>|null $items */
    public function count(?array $items = null): int
    {
        return (int) array_sum(array_column($items ?? $this->items(), 'quantity'));
    }

    public function clear(): void
    {
        $this->carts->clear($this->id());
    }

    /** Signed-in customers' carts remember their owner (abandoned-cart reminders). Never breaks the cart. */
    private function stampCustomer(): void
    {
        try {
            $customerId = $this->auth->id();
            if ($customerId !== null) {
                $this->carts->attachCustomer($this->id(), $customerId);
            }
        } catch (Throwable) {
            // store not migrated yet
        }
    }

    private function id(): int
    {
        return $this->cartId ??= $this->carts->idForSession($this->session->id());
    }
}
