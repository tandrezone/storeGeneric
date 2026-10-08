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
        return $this->translator->trans('Cash on delivery');
    }
    public function description(): string
    {
        return $this->translator->trans('Pay the courier in cash when your order arrives.');
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
        // COD_NOTE is the store's own text (one language); the default is translated.
        $note = $this->config->has('COD_NOTE')
            ? $this->config->get('COD_NOTE')
            : $this->translator->trans('Please have the exact amount ready when the courier arrives.');
        $amount = '<strong>' . htmlspecialchars($this->plainAmount((float) $order['total'])) . '</strong>';

        return '<h2>' . htmlspecialchars($this->translator->trans('Cash on delivery')) . '</h2><p>'
            . $this->translator->trans('You\'ll pay {amount} on delivery.', ['amount' => $amount])
            . ' ' . htmlspecialchars($note) . '</p>';
    }
}
