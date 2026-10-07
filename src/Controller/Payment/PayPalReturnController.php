<?php

declare(strict_types=1);

namespace App\Controller\Payment;

use App\Http\Responder;
use App\Payment\Method\PayPalMethod;
use App\Payment\PaymentRecorder;
use App\Repository\OrderRepository;
use App\Service\OrderLinks;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * PayPal sends the customer back here after approving
 * (?order=<number>&key=<order link key>&token=<PayPal order id>). The payment
 * is captured server-side, checked against the order (reference here, amount
 * and currency in PaymentRecorder), recorded, and the customer forwarded to
 * the confirmation page.
 */
final class PayPalReturnController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly PayPalMethod $payPal,
        private readonly OrderRepository $orders,
        private readonly PaymentRecorder $recorder,
        private readonly OrderLinks $links,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $orderNumber = trim((string) ($query['order'] ?? ''));
        $paypalOrderId = trim((string) ($query['token'] ?? ''));

        // The key proves the link came from our checkout; without it, don't reveal anything.
        if (!$this->links->canView($orderNumber, (string) ($query['key'] ?? ''))) {
            return $this->responder->redirectToRoute('order.confirmation');
        }
        $confirmation = $this->responder->redirect($this->links->confirmationPath($orderNumber));

        $order = $this->orders->findByNumber($orderNumber);
        if ($order === null || $paypalOrderId === '' || $order['payment_method'] !== 'paypal' || $order['payment_status'] === 'paid') {
            return $confirmation;
        }

        try {
            $capture = $this->payPal->capture($paypalOrderId);
            $unit = $capture['purchase_units'][0] ?? [];
            $payment = $unit['payments']['captures'][0] ?? [];
            $belongs = ($unit['reference_id'] ?? '') === $orderNumber || ($payment['custom_id'] ?? '') === $orderNumber;
            if (!$belongs) {
                $this->logger->warning('PayPal capture does not belong to the order', ['order' => $orderNumber, 'paypal_order' => $paypalOrderId]);

                return $confirmation;
            }

            $status = ($capture['status'] ?? '') === 'COMPLETED'
                ? (($payment['status'] ?? '') === 'COMPLETED' ? 'paid' : 'paying')
                : 'failed';

            $this->recorder->record(
                $orderNumber,
                'paypal',
                $status,
                (string) ($payment['id'] ?? $paypalOrderId),
                isset($payment['amount']['value']) ? (float) $payment['amount']['value'] : null,
                (string) ($payment['amount']['currency_code'] ?? ''),
                (string) json_encode($capture)
            );
        } catch (Throwable $e) {
            $this->logger->error('PayPal capture failed', ['order' => $orderNumber, 'error' => $e->getMessage()]);
        }

        return $confirmation;
    }
}
