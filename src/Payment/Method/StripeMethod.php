<?php

declare(strict_types=1);

namespace App\Payment\Method;

use App\Http\Router;
use App\Infrastructure\HttpClient;
use App\Payment\AbstractPaymentMethod;
use App\Support\Config;
use RuntimeException;

/**
 * Stripe Checkout (hosted page: cards, Apple Pay, Google Pay, and whatever
 * else is switched on in the Stripe dashboard). Payment is confirmed by
 * the signed webhook in StripeWebhookController (/payment/stripe).
 * Docs: https://docs.stripe.com/api/checkout/sessions/create
 */
final class StripeMethod extends AbstractPaymentMethod
{
    public function __construct(Config $config, Router $router, private readonly HttpClient $http)
    {
        parent::__construct($config, $router);
    }

    public function id(): string
    {
        return 'stripe';
    }
    public function label(): string
    {
        return 'Card (Stripe)';
    }
    public function description(): string
    {
        return 'Pay by card, Apple Pay or Google Pay on Stripe\'s secure page.';
    }

    protected function requiredSettings(): array
    {
        return ['STRIPE_SECRET_KEY', 'STRIPE_WEBHOOK_SECRET'];
    }

    public function start(array $order): string
    {
        $number = (string) $order['order_number'];

        $response = $this->http->request('POST', 'https://api.stripe.com/v1/checkout/sessions', [
            'Authorization: Bearer ' . $this->config->get('STRIPE_SECRET_KEY'),
        ], [
            'mode'                => 'payment',
            'client_reference_id' => $number,
            'customer_email'      => (string) $order['email'],
            'metadata'            => ['order_number' => $number],
            'success_url'         => $this->confirmationUrl($number),
            'cancel_url'          => $this->confirmationUrl($number),
            'line_items'          => [[
                'quantity'   => 1,
                'price_data' => [
                    'currency'     => strtolower($this->currency()),
                    'unit_amount'  => $this->minorUnits((float) $order['total']),
                    'product_data' => ['name' => 'Order ' . $number],
                ],
            ]],
        ], form: true);

        $url = $response['body']['url'] ?? null;
        if (!is_string($url) || $url === '') {
            $error = $response['body']['error']['message'] ?? ('HTTP ' . $response['status']);
            throw new RuntimeException('Stripe: ' . $error);
        }

        return $url;
    }

    /**
     * Verifies the Stripe-Signature header ("t=…,v1=…"): HMAC-SHA256 of
     * "{t}.{raw body}" with the endpoint's whsec_ secret, within 5 minutes.
     */
    public static function verifySignature(string $payload, string $header, string $secret, int $tolerance = 300): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $timestamp = $v;
            } elseif ($k === 'v1') {
                $signatures[] = $v;
            }
        }

        if ($timestamp === null || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }
}
