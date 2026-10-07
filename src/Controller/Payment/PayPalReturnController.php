<?php

declare(strict_types=1);

namespace App\Controller\Payment;

use App\Http\Responder;
use App\Payment\Method\PayPalMethod;
use App\Payment\PaymentRecorder;
use App\Repository\OrderRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * PayPal sends the customer back here after approving (?order=<number>&token=<PayPal order id>).
 * The payment is captured server-side, checked against the order (reference
 * and amount), recorded, and the customer forwarded to the confirmation page.
 */
final class PayPalReturnController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly PayPalMethod $payPal,
        private readonly OrderRepository $orders,
        private readonly PaymentRecorder $recorder,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $orderNumber = trim((string) ($query['order'] ?? ''));
        $paypalOrderId = trim((string) ($query['token'] ?? ''));
        $confirmation = $this->responder->redirectToRoute('order.confirmation', [], ['order' => $orderNumber]);

        $order = $orderNumber !== '' ? $this->orders->findByNumber($orderNumber) : null;
        if ($order === null || $paypalOrderId === '' || $order['payment_method'] !== 'paypal' || $order['payment_status'] === 'paid') {
            return $confirmation;
        }

        try {
            $capture = $this->payPal->capture($paypalOrderId);
            $unit = $capture['purchase_units'][0] ?? [];
            $payment = $unit['payments']['captures'][0] ?? [];
            $belongs = ($unit['reference_id'] ?? '') === $orderNumber || ($payment['custom_id'] ?? '') === $orderNumber;
            $amount = (float) ($payment['amount']['value'] ?? 0);

            $status = ($capture['status'] ?? '') === 'COMPLETED' && $belongs && $amount + 0.001 >= (float) $order['total']
                ? (($payment['status'] ?? '') === 'COMPLETED' ? 'paid' : 'paying')
                : 'failed';

            $this->recorder->record(
                $orderNumber,
                'paypal',
                $status,
                (string) ($payment['id'] ?? $paypalOrderId),
                $amount ?: null,
                (string) ($payment['amount']['currency_code'] ?? ''),
                (string) json_encode($capture)
            );
        } catch (Throwable $e) {
            $this->logger->error('PayPal capture failed', ['order' => $orderNumber, 'error' => $e->getMessage()]);
        }

        return $confirmation;
    }
}
