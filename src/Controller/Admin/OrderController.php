<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Exception\HttpException;
use App\Http\Responder;
use App\Http\Session;
use App\Payment\PaymentMethod;
use App\Payment\PaymentRecorder;
use App\Payment\PaymentRegistry;
use App\Repository\OrderRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class OrderController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly OrderRepository $orders,
        private readonly PaymentRegistry $payments,
        private readonly PaymentRecorder $recorder,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $status = (string) ($request->getQueryParams()['status'] ?? '');
        $status = in_array($status, OrderRepository::STATUSES, true) ? $status : null;

        return $this->responder->view($request, 'admin/orders.html.twig', [
            'orders'         => $this->orders->findForAdmin($status),
            'statuses'       => OrderRepository::STATUSES,
            'status_filter'  => $status,
            'payment_labels' => array_map(static fn (PaymentMethod $m) => $m->label(), $this->payments->all()),
        ]);
    }

    public function show(ServerRequestInterface $request, int $id): ResponseInterface
    {
        $order = $this->orders->find($id) ?? throw HttpException::notFound('Order not found.');

        return $this->responder->view($request, 'admin/order.html.twig', [
            'order'         => $order,
            'statuses'      => OrderRepository::STATUSES,
            'payment_label' => $this->payments->label((string) $order['payment_method']),
        ]);
    }

    public function handle(ServerRequestInterface $request, int $id): ResponseInterface
    {
        $order = $this->orders->find($id) ?? throw HttpException::notFound('Order not found.');
        $body = (array) $request->getParsedBody();

        switch ((string) ($body['action'] ?? '')) {
            case 'set_status':
                if ($this->orders->updateStatus($id, (string) ($body['status'] ?? ''))) {
                    $this->session->flash('success', 'Status updated.');
                }
                break;

            case 'mark_paid':
                if ($order['payment_status'] !== 'paid') {
                    $this->recorder->record($order['order_number'], 'manual', 'paid', 'admin', (float) $order['total']);
                    $this->session->flash('success', 'Payment recorded — order marked as paid.');
                }
                break;
        }

        return $this->responder->redirectToRoute('admin.order', ['id' => $id]);
    }
}
