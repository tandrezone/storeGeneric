<?php

declare(strict_types=1);

namespace App\Payment\Method;

use App\Payment\AbstractPaymentMethod;

/**
 * Manual bank transfer: the customer gets the account details and uses the
 * order number as the reference; an admin marks the order paid once the
 * money arrives (admin/order.php → "Mark as paid").
 */
final class BankTransferMethod extends AbstractPaymentMethod
{
    public function id(): string
    {
        return 'bank_transfer';
    }
    public function label(): string
    {
        return 'Bank transfer';
    }
    public function description(): string
    {
        return 'Transfer the total to our bank account. We ship once it arrives.';
    }

    protected function requiredSettings(): array
    {
        return ['BANK_ACCOUNT_NAME', 'BANK_IBAN'];
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
        $rows = [
            'Account holder' => $this->config->get('BANK_ACCOUNT_NAME'),
            'Bank'           => $this->config->get('BANK_NAME'),
            'IBAN'           => $this->config->get('BANK_IBAN'),
            'BIC / SWIFT'    => $this->config->get('BANK_BIC'),
            'Amount'         => $this->plainAmount((float) $order['total']),
            'Reference'      => (string) $order['order_number'],
        ];

        $html = '<h2>Pay by bank transfer</h2><p>Please transfer the amount below and use your order number as the payment reference.</p><div class="address-block">';
        foreach ($rows as $label => $value) {
            if ($value === '') {
                continue;
            }
            $html .= '<span class="label">' . htmlspecialchars($label) . '</span><br>' . htmlspecialchars($value) . '<br>';
        }

        return $html . '</div>';
    }
}
