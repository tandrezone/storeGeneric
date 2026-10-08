<?php

declare(strict_types=1);

namespace App\Payment\Method;

use App\Http\Router;
use App\I18n\Translator;
use App\Infrastructure\HttpClient;
use App\Payment\AbstractPaymentMethod;
use App\Service\OrderLinks;
use App\Support\Config;
use App\Support\Money;
use RuntimeException;

/**
 * PayPal Checkout via the Orders v2 API. The customer approves on PayPal,
 * comes back to /payment/paypal/return (PayPalReturnController), and that handler
 * captures the payment server-side.
 * Docs: https://developer.paypal.com/docs/api/orders/v2/
 */
final class PayPalMethod extends AbstractPaymentMethod
{
    public function __construct(Config $config, Router $router, OrderLinks $links, Translator $translator, private readonly HttpClient $http)
    {
        parent::__construct($config, $router, $links, $translator);
    }

    public function id(): string
    {
        return 'paypal';
    }
    public function label(): string
    {
        return 'PayPal';
    }
    public function description(): string
    {
        return $this->translator->trans('Pay with your PayPal balance, bank or card via PayPal.');
    }

    protected function requiredSettings(): array
    {
        return ['PAYPAL_CLIENT_ID', 'PAYPAL_CLIENT_SECRET'];
    }

    public function baseUrl(): string
    {
        return filter_var($this->config->get('PAYPAL_SANDBOX', 'true'), FILTER_VALIDATE_BOOLEAN)
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }

    public function accessToken(): string
    {
        $response = $this->http->request('POST', $this->baseUrl() . '/v1/oauth2/token', [
            'Authorization: Basic ' . base64_encode($this->config->get('PAYPAL_CLIENT_ID') . ':' . $this->config->get('PAYPAL_CLIENT_SECRET')),
        ], ['grant_type' => 'client_credentials'], form: true);

        $token = $response['body']['access_token'] ?? '';
        if ($token === '') {
            throw new RuntimeException('PayPal: could not authenticate (HTTP ' . $response['status'] . ').');
        }

        return $token;
    }

    public function start(array $order): string
    {
        $number = (string) $order['order_number'];
        $return = $this->absoluteUrl('payment.paypal.return', [], $this->links->query($number));

        $response = $this->http->request('POST', $this->baseUrl() . '/v2/checkout/orders', [
            'Authorization: Bearer ' . $this->accessToken(),
            'PayPal-Request-Id: ' . $number,
        ], [
            'intent'         => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $number,
                'custom_id'    => $number,
                'description'  => 'Order ' . $number,
                'amount'       => [
                    'currency_code' => $this->currency(),
                    'value'         => number_format((float) $order['total'], Money::exponent($this->currency()), '.', ''),
                ],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'user_action' => 'PAY_NOW',
                'return_url'  => $return,
                'cancel_url'  => $this->confirmationUrl($number),
            ]]],
        ]);

        foreach ($response['body']['links'] ?? [] as $link) {
            if (in_array($link['rel'] ?? '', ['payer-action', 'approve'], true)) {
                return (string) $link['href'];
            }
        }

        $error = $response['body']['message'] ?? ('HTTP ' . $response['status']);
        throw new RuntimeException('PayPal: ' . $error);
    }

    /**
     * Captures an approved PayPal order. Returns the decoded capture response.
     *
     * @return array<string, mixed>
     */
    public function capture(string $paypalOrderId): array
    {
        $response = $this->http->request(
            'POST',
            $this->baseUrl() . '/v2/checkout/orders/' . rawurlencode($paypalOrderId) . '/capture',
            ['Authorization: Bearer ' . $this->accessToken(), 'Content-Type: application/json'],
            '{}'
        );

        return $response['body'];
    }
}
