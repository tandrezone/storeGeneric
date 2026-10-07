<?php

declare(strict_types=1);

namespace App\Payment\Method;

use App\Http\Router;
use App\Infrastructure\OxaPayClient;
use App\Payment\AbstractPaymentMethod;
use App\Support\Config;
use RuntimeException;

/** OxaPay crypto invoices, confirmed by the HMAC-signed callback (OxaPayWebhookController). */
final class OxaPayMethod extends AbstractPaymentMethod
{
    public function __construct(Config $config, Router $router, private readonly OxaPayClient $client)
    {
        parent::__construct($config, $router);
    }

    public function id(): string
    {
        return 'oxapay';
    }

    public function label(): string
    {
        return 'Crypto (OxaPay)';
    }

    public function description(): string
    {
        return 'Pay in cryptocurrency on OxaPay\'s payment page.';
    }

    protected function requiredSettings(): array
    {
        return ['OXAPAY_MERCHANT_KEY'];
    }

    /** OxaPay was the only method before payments became configurable, so it stays on unless disabled. */
    protected function enabledByDefault(): bool
    {
        return true;
    }

    public function start(array $order): string
    {
        $invoice = $this->client->createInvoice(
            (string) $order['order_number'],
            (float) $order['total'],
            (string) $order['email'],
            $this->absoluteUrl('payment.oxapay'),
            $this->confirmationUrl((string) $order['order_number'])
        );

        $url = $invoice['data']['payment_url'] ?? '';
        if (!is_string($url) || $url === '') {
            throw new RuntimeException('OxaPay: ' . ($invoice['message'] ?? 'could not create the invoice.'));
        }

        return $url;
    }
}
