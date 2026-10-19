<?php

declare(strict_types=1);

namespace App\Payment;

/**
 * One way a customer can pay at checkout.
 *
 * Online methods return a provider URL from start() and confirm payment
 * later (webhook or return handler). Offline methods (bank transfer, cash
 * on delivery) return null and show instructions() on the confirmation
 * page; an admin marks those orders paid by hand.
 */
interface PaymentMethod
{
    /** Stable key stored on orders.payment_method, e.g. "stripe". */
    public function id(): string;

    /** Name shown to customers at checkout. */
    public function label(): string;

    /** One-line explanation shown under the label. */
    public function description(): string;

    /** True when switched on in .env AND its required settings are present. */
    public function isEnabled(): bool;

    /** @return list<string> required settings that are still empty */
    public function missingSettings(): array;

    public function isOffline(): bool;

    /**
     * Starts payment for a freshly created order.
     *
     * @param array{order_number: string, total: float, email: string} $order
     * @return string|null URL to send the customer to, or null for offline methods
     */
    public function start(array $order): ?string;

    /** True when refund() can return money through the provider's API. */
    public function supportsRefund(): bool;

    /**
     * Refunds a payment through the provider (full amount, in the store currency).
     *
     * @param string $reference      provider id stored with the "paid" payment (payments.track_id)
     * @param string $idempotencyKey same key for the same refund (the order number), so a retry never refunds twice
     * @return string the provider's refund id
     * @throws \RuntimeException when unsupported or the provider refuses / can't be reached
     */
    public function refund(string $reference, float $amount, string $idempotencyKey): string;

    /**
     * HTML shown on the confirmation page while the order is unpaid ('' for none).
     *
     * @param array<string, mixed> $order
     */
    public function instructions(array $order): string;
}
