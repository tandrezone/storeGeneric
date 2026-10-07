<?php

declare(strict_types=1);

namespace App\Controller\Payment;

use App\Http\Responder;
use App\Payment\Method\StripeMethod;
use App\Payment\PaymentRecorder;
use App\Support\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Stripe webhook. In the Stripe dashboard, add an endpoint for
 * {APP_URL}/payment/stripe with the events checkout.session.completed,
 * checkout.session.async_payment_succeeded, checkout.session.async_payment_failed
 * and checkout.session.expired, and put its signing secret in STRIPE_WEBHOOK_SECRET.
 */
final class StripeWebhookController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly PaymentRecorder $recorder,
        private readonly Config $config,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $raw = (string) $request->getBody();
        if (!StripeMethod::verifySignature($raw, $request->getHeaderLine('Stripe-Signature'), $this->config->get('STRIPE_WEBHOOK_SECRET'))) {
            return $this->responder->text('invalid signature', 400);
        }

        $event = json_decode($raw, true);
        $session = $event['data']['object'] ?? null;
        $orderNumber = (string) ($session['client_reference_id'] ?? $session['metadata']['order_number'] ?? '');
        if (!is_array($session) || $orderNumber === '') {
            return $this->responder->text('ignored');
        }

        $status = match ($event['type'] ?? '') {
            'checkout.session.completed' => ($session['payment_status'] ?? '') === 'paid' ? 'paid' : 'paying',
            'checkout.session.async_payment_succeeded' => 'paid',
            'checkout.session.async_payment_failed' => 'failed',
            'checkout.session.expired' => 'expired',
            default => null,
        };

        if ($status !== null) {
            $this->recorder->record(
                $orderNumber,
                'stripe',
                $status,
                (string) ($session['payment_intent'] ?? $session['id'] ?? ''),
                isset($session['amount_total']) ? $session['amount_total'] / 100 : null,
                strtoupper((string) ($session['currency'] ?? '')),
                $raw
            );
        }

        return $this->responder->text('ok');
    }
}
