<?php

declare(strict_types=1);

namespace App\Controller\Payment;

use App\Http\Responder;
use App\Infrastructure\OxaPayClient;
use App\Payment\PaymentRecorder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * OxaPay callback: JSON on each status change ("paying", "paid", "failed",
 * "expired"), retried until it gets HTTP 200 "ok". The HMAC header is an
 * HMAC-SHA512 of the raw body keyed with the merchant key; anything else is
 * rejected. https://docs.oxapay.com/webhook
 */
final class OxaPayWebhookController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly OxaPayClient $oxaPay,
        private readonly PaymentRecorder $recorder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $raw = (string) $request->getBody();
        $secret = $this->oxaPay->merchantKey();
        $signature = $request->getHeaderLine('HMAC');

        if ($secret === '' || $signature === '' || !hash_equals(hash_hmac('sha512', $raw, $secret), $signature)) {
            return $this->responder->text('invalid signature', 401);
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['order_id']) || empty($data['status'])) {
            return $this->responder->text('invalid payload', 400);
        }

        $status = strtolower((string) $data['status']);
        $this->recorder->record(
            (string) $data['order_id'],
            'oxapay',
            in_array($status, PaymentRecorder::STATUSES, true) ? $status : 'paying',
            (string) ($data['track_id'] ?? ''),
            isset($data['amount']) ? (float) $data['amount'] : null,
            (string) ($data['currency'] ?? ''),
            $raw
        );

        return $this->responder->text('ok');
    }
}
