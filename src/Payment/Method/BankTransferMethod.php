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
        return $this->translator->trans('Bank transfer');
    }
    public function description(): string
    {
        return $this->translator->trans('Transfer the total to our bank account. We ship once it arrives.');
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
        $t = $this->translator;
        $rows = [
            [$t->trans('Account holder'), $this->config->get('BANK_ACCOUNT_NAME')],
            [$t->trans('Bank'), $this->config->get('BANK_NAME')],
            ['IBAN', $this->config->get('BANK_IBAN')],
            ['BIC / SWIFT', $this->config->get('BANK_BIC')],
            [$t->trans('Amount'), $this->plainAmount((float) $order['total'])],
            [$t->trans('Reference'), (string) $order['order_number']],
        ];

        $html = '<h2>' . htmlspecialchars($t->trans('Pay by bank transfer')) . '</h2><p>'
            . htmlspecialchars($t->trans('Please transfer the amount below and use your order number as the payment reference.'))
            . '</p><div class="address-block">';
        foreach ($rows as [$label, $value]) {
            if ($value === '') {
                continue;
            }
            $html .= '<span class="label">' . htmlspecialchars($label) . '</span><br>' . htmlspecialchars($value) . '<br>';
        }

        return $html . '</div>';
    }
}
