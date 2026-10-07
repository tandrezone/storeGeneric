<?php

declare(strict_types=1);

namespace App\Payment;

use App\Http\Router;
use App\Service\OrderLinks;
use App\Support\Config;
use App\Support\Money;

/**
 * Shared plumbing: reads PAYMENT_<ID>_ENABLED and the method's required
 * settings from the configuration, and builds absolute callback URLs.
 */
abstract class AbstractPaymentMethod implements PaymentMethod
{
    public function __construct(
        protected readonly Config $config,
        protected readonly Router $router,
        protected readonly OrderLinks $links,
    ) {
    }

    /** @return list<string> settings that must be non-empty for the method to work */
    protected function requiredSettings(): array
    {
        return [];
    }

    /** Used when PAYMENT_<ID>_ENABLED isn't set at all. */
    protected function enabledByDefault(): bool
    {
        return false;
    }

    public function isEnabled(): bool
    {
        $on = $this->config->bool('PAYMENT_' . strtoupper($this->id()) . '_ENABLED', $this->enabledByDefault());

        return $on && $this->missingSettings() === [];
    }

    public function missingSettings(): array
    {
        return array_values(array_filter($this->requiredSettings(), fn (string $key) => !$this->config->has($key)));
    }

    public function isOffline(): bool
    {
        return false;
    }

    public function instructions(array $order): string
    {
        return '';
    }

    /**
     * Absolute URL of a named route, based on APP_URL.
     *
     * @param array<string, scalar> $params
     * @param array<string, scalar|null> $query
     */
    protected function absoluteUrl(string $route, array $params = [], array $query = []): string
    {
        return rtrim($this->config->get('APP_URL', 'http://localhost'), '/') . $this->router->url($route, $params, $query);
    }

    /** Absolute confirmation URL, signed so only the customer can open it. */
    protected function confirmationUrl(string $orderNumber): string
    {
        return $this->absoluteUrl('order.confirmation', [], $this->links->query($orderNumber));
    }

    protected function currency(): string
    {
        return strtoupper($this->config->get('STORE_CURRENCY', 'EUR'));
    }

    /** Amount in the currency's smallest unit (cents, or whole yen for JPY …). */
    protected function minorUnits(float $amount): int
    {
        return Money::toMinor($amount, $this->currency());
    }

    /** "12.50 EUR" / "1200 JPY" for payment instructions. */
    protected function plainAmount(float $amount): string
    {
        return number_format($amount, Money::exponent($this->currency()), '.', '') . ' ' . $this->currency();
    }
}
