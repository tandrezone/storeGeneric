<?php

declare(strict_types=1);

namespace App\Controller\Payment;

use App\Http\Responder;
use App\Payment\Method\RevolutMethod;
use App\Payment\PaymentRecorder;
use App\Support\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Revolut Merchant webhook. Register {APP_URL}/payment/revolut for the
 * ORDER_* events and put the signing secret in REVOLUT_WEBHOOK_SECRET.
 * The event only says which order changed; its real state is re-fetched
 * from Revolut, so a signed-but-wrong payload can't mark an order paid.
 */
final class RevolutWebhookController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly RevolutMethod $revolut,
        private readonly PaymentRecorder $recorder,
        private readonly Config $config,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $raw = (string) $request->getBody();
        $valid = RevolutMethod::verifySignature(
            $raw,
            $request->getHeaderLine('Revolut-Request-Timestamp'),
            $request->getHeaderLine('Revolut-Signature'),
            $this->config->get('REVOLUT_WEBHOOK_SECRET')
        );
        if (!$valid) {
            return $this->responder->text('invalid signature', 401);
        }

        $event = json_decode($raw, true);
        $revolutOrderId = (string) ($event['order_id'] ?? '');
        if ($revolutOrderId === '') {
            return $this->responder->text('ignored');
        }

        try {
            $remote = $this->revolut->fetchOrder($revolutOrderId);
        } catch (Throwable) {
            return $this->responder->text('lookup failed', 500); // Revolut retries
        }

        $orderNumber = (string) ($remote['merchant_order_data']['reference'] ?? $remote['merchant_order_ext_ref'] ?? $event['merchant_order_ext_ref'] ?? '');
        $status = match (strtolower((string) ($remote['state'] ?? ''))) {
            'completed' => 'paid',
            'authorised', 'processing', 'pending' => 'paying',
            'failed' => 'failed',
            'cancelled' => 'expired',
            default => null,
        };

        if ($orderNumber !== '' && $status !== null) {
            $this->recorder->record(
                $orderNumber,
                'revolut',
                $status,
                $revolutOrderId,
                isset($remote['amount']) ? $remote['amount'] / 100 : null,
                (string) ($remote['currency'] ?? ''),
                $raw
            );
        }

        return $this->responder->text('ok');
    }
}
