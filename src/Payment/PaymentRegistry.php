<?php

declare(strict_types=1);

namespace App\Payment;

use App\Payment\Method\BankTransferMethod;
use App\Payment\Method\CashOnDeliveryMethod;
use App\Payment\Method\OxaPayMethod;
use App\Payment\Method\PayPalMethod;
use App\Payment\Method\RevolutMethod;
use App\Payment\Method\StripeMethod;

/**
 * Every payment method the store knows about, in checkout order.
 * To add one: implement PaymentMethod and add it to the constructor.
 */
final class PaymentRegistry
{
    /** @var array<string, PaymentMethod> */
    private array $methods = [];

    public function __construct(
        StripeMethod $stripe,
        PayPalMethod $payPal,
        RevolutMethod $revolut,
        OxaPayMethod $oxaPay,
        BankTransferMethod $bankTransfer,
        CashOnDeliveryMethod $cashOnDelivery,
    ) {
        foreach ([$stripe, $payPal, $revolut, $oxaPay, $bankTransfer, $cashOnDelivery] as $method) {
            $this->methods[$method->id()] = $method;
        }
    }

    /** @return array<string, PaymentMethod> */
    public function all(): array
    {
        return $this->methods;
    }

    /** @return array<string, PaymentMethod> */
    public function enabled(): array
    {
        return array_filter($this->methods, static fn (PaymentMethod $m) => $m->isEnabled());
    }

    public function get(string $id): ?PaymentMethod
    {
        return $this->methods[$id] ?? null;
    }

    /** An enabled method, or null if unknown or disabled. */
    public function enabledMethod(string $id): ?PaymentMethod
    {
        $method = $this->get($id);

        return $method !== null && $method->isEnabled() ? $method : null;
    }

    public function label(string $id): string
    {
        return $this->get($id)?->label() ?? $id;
    }
}
