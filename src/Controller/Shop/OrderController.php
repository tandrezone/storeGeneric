<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Http\Responder;
use App\Payment\PaymentRegistry;
use App\Repository\OrderRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Where customers land after paying (or placing an offline-payment order). */
final class OrderController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly OrderRepository $orders,
        private readonly PaymentRegistry $payments,
    ) {
    }

    public function confirmation(ServerRequestInterface $request): ResponseInterface
    {
        $number = trim((string) ($request->getQueryParams()['order'] ?? ''));
        $order = $number !== '' ? $this->orders->findByNumber($number) : null;
        $method = $order !== null ? $this->payments->get((string) $order['payment_method']) : null;

        return $this->responder->view($request, 'shop/confirmation.html.twig', [
            'order'                => $order,
            'payment_label'        => $order !== null ? $this->payments->label((string) $order['payment_method']) : '',
            'payment_instructions' => $method !== null && $method->isOffline() ? $method->instructions($order) : '',
        ]);
    }
}
