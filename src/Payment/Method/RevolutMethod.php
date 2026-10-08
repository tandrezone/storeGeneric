<?php

declare(strict_types=1);

namespace App\Payment\Method;

use App\Http\Router;
use App\I18n\Translator;
use App\Infrastructure\HttpClient;
use App\Payment\AbstractPaymentMethod;
use App\Service\OrderLinks;
use App\Support\Config;
use RuntimeException;

/**
 * Revolut Business Merchant API: creates an order and sends the customer
 * to its hosted checkout_url. Confirmed by RevolutWebhookController (/payment/revolut),
 * which re-fetches the order from Revolut before trusting it.
 * Docs: https://developer.revolut.com/docs/merchant/create-order
 */
final class RevolutMethod extends AbstractPaymentMethod
{
    public function __construct(Config $config, Router $router, OrderLinks $links, Translator $translator, private readonly HttpClient $http)
    {
        parent::__construct($config, $router, $links, $translator);
    }

    public function id(): string
    {
        return 'revolut';
    }
    public function label(): string
    {
        return 'Revolut Pay';
    }
    public function description(): string
    {
        return $this->translator->trans('Pay with Revolut, card, Apple Pay or Google Pay on Revolut\'s checkout.');
    }

    protected function requiredSettings(): array
    {
        return ['REVOLUT_SECRET_KEY', 'REVOLUT_WEBHOOK_SECRET'];
    }

    public function baseUrl(): string
    {
        return filter_var($this->config->get('REVOLUT_SANDBOX', 'true'), FILTER_VALIDATE_BOOLEAN)
            ? 'https://sandbox-merchant.revolut.com/api'
            : 'https://merchant.revolut.com/api';
    }

    /** @return list<string> */
    public function headers(): array
    {
        return [
            'Authorization: Bearer ' . $this->config->get('REVOLUT_SECRET_KEY'),
            'Revolut-Api-Version: ' . $this->config->get('REVOLUT_API_VERSION', '2026-08-17'),
            'Accept: application/json',
        ];
    }

    public function start(array $order): string
    {
        $number = (string) $order['order_number'];

        $response = $this->http->request('POST', $this->baseUrl() . '/orders', $this->headers(), [
            'amount'              => $this->minorUnits((float) $order['total']),
            'currency'            => $this->currency(),
            'description'         => 'Order ' . $number,
            'customer'            => ['email' => (string) $order['email']],
            'merchant_order_data' => ['reference' => $number],
            'redirect_url'        => $this->confirmationUrl($number),
        ]);

        $url = $response['body']['checkout_url'] ?? '';
        if ($url === '') {
            $error = $response['body']['message'] ?? ('HTTP ' . $response['status']);
            throw new RuntimeException('Revolut: ' . $error);
        }

        return (string) $url;
    }

    /** @return array<string, mixed> */
    public function fetchOrder(string $revolutOrderId): array
    {
        return $this->http->request('GET', $this->baseUrl() . '/orders/' . rawurlencode($revolutOrderId), $this->headers())['body'];
    }

    /**
     * Revolut-Signature is "v1=<hex>" (possibly several, comma-separated):
     * HMAC-SHA256 of "v1.{Revolut-Request-Timestamp}.{raw body}" with the
     * webhook signing secret. Timestamp (ms) must be within 5 minutes.
     */
    public static function verifySignature(string $payload, string $timestamp, string $header, string $secret): bool
    {
        if ($secret === '' || $header === '' || !ctype_digit($timestamp)) {
            return false;
        }

        $ts = (int) $timestamp;
        $seconds = $ts > 1_000_000_000_000 ? intdiv($ts, 1000) : $ts;
        if (abs(time() - $seconds) > 300) {
            return false;
        }

        $expected = 'v1=' . hash_hmac('sha256', 'v1.' . $timestamp . '.' . $payload, $secret);
        foreach (explode(',', $header) as $sig) {
            if (hash_equals($expected, trim($sig))) {
                return true;
            }
        }

        return false;
    }
}
