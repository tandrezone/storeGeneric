<?php

declare(strict_types=1);

namespace App\Payment;

use App\Http\Router;
use App\Support\Config;

/**
 * Shared plumbing: reads PAYMENT_<ID>_ENABLED and the method's required
 * settings from the configuration, and builds absolute callback URLs.
 */
abstract class AbstractPaymentMethod implements PaymentMethod
{
    public function __construct(
        protected readonly Config $config,
        protected readonly Router $router,
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

    protected function confirmationUrl(string $orderNumber): string
    {
        return $this->absoluteUrl('order.confirmation', [], ['order' => $orderNumber]);
    }

    protected function currency(): string
    {
        return strtoupper($this->config->get('STORE_CURRENCY', 'EUR'));
    }

    /** Amount in the currency's smallest unit (cents). */
    protected function minorUnits(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
