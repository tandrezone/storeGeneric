<?php

declare(strict_types=1);

namespace App\Payment\Method;

use App\Payment\AbstractPaymentMethod;

/** Pay the courier on delivery. An admin marks the order paid afterwards. */
final class CashOnDeliveryMethod extends AbstractPaymentMethod
{
    public function id(): string
    {
        return 'cod';
    }
    public function label(): string
    {
        return 'Cash on delivery';
    }
    public function description(): string
    {
        return 'Pay the courier in cash when your order arrives.';
    }

    public function isOffline(): bool
    {
        return true;
    }

    public function start(array $order): ?string
    {
        return null;
    }

    public function instructions(array $order): string
    {
        $note = $this->config->get('COD_NOTE', 'Please have the exact amount ready when the courier arrives.');

        return '<h2>Cash on delivery</h2><p>You\'ll pay <strong>'
            . htmlspecialchars($this->plainAmount((float) $order['total']))
            . '</strong> on delivery. ' . htmlspecialchars($note) . '</p>';
    }
}
