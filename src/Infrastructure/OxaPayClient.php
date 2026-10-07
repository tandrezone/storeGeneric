<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Support\Config;

/**
 * Minimal OxaPay merchant API client.
 * Docs: https://docs.oxapay.com/api-reference/payment/generate-invoice
 */
final class OxaPayClient
{
    private const BASE_URL = 'https://api.oxapay.com';
    private const LIFETIME_MINUTES = 60;

    public function __construct(
        private readonly HttpClient $http,
        private readonly Config $config,
    ) {
    }

    public function merchantKey(): string
    {
        return $this->config->get('OXAPAY_MERCHANT_KEY');
    }

    /**
     * Creates a payment invoice. The response includes data.payment_url and data.track_id on success.
     *
     * @return array<mixed>
     */
    public function createInvoice(string $orderNumber, float $amount, string $email, string $callbackUrl, string $returnUrl): array
    {
        return $this->http->request('POST', self::BASE_URL . '/v1/payment/invoice', $this->headers(), [
            'amount'       => $amount,
            'currency'     => strtoupper($this->config->get('STORE_CURRENCY', 'EUR')),
            'lifetime'     => self::LIFETIME_MINUTES,
            'order_id'     => $orderNumber,
            'email'        => $email,
            'description'  => 'Order ' . $orderNumber,
            'callback_url' => $callbackUrl,
            'return_url'   => $returnUrl,
            'sandbox'      => $this->config->bool('OXAPAY_SANDBOX', true),
        ])['body'];
    }

    /** @return array<mixed> payment details by track_id (for reconciliation) */
    public function paymentInfo(string $trackId): array
    {
        return $this->http->request('GET', self::BASE_URL . '/v1/payment/' . rawurlencode($trackId), $this->headers())['body'];
    }

    /** @return list<string> */
    private function headers(): array
    {
        return ['merchant_api_key: ' . $this->merchantKey()];
    }
}
