<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Exception\HttpException;
use App\Http\Responder;
use App\Http\Session;
use App\I18n\Translator;
use App\Payment\PaymentMethod;
use App\Payment\PaymentRegistry;
use App\Repository\OrderRepository;
use App\Repository\PaymentRepository;
use App\Service\OrderService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class OrderController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly OrderRepository $orders,
        private readonly PaymentRepository $paymentLog,
        private readonly PaymentRegistry $payments,
        private readonly OrderService $service,
        private readonly Translator $translator,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $status = (string) ($query['status'] ?? '');
        $filters = [
            'status' => in_array($status, OrderRepository::STATUSES, true) ? $status : null,
            'q'      => mb_substr(trim((string) ($query['q'] ?? '')), 0, 100),
            'from'   => $this->date($query['from'] ?? ''),
            'to'     => $this->date($query['to'] ?? ''),
        ];

        $total = $this->orders->countForAdmin($filters);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($totalPages, max(1, (int) ($query['page'] ?? 1)));

        return $this->responder->view($request, 'admin/orders.html.twig', [
            'orders'         => $this->orders->pageForAdmin($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'statuses'       => OrderRepository::STATUSES,
            'status_filter'  => $filters['status'],
            'filters'        => $filters,
            'total'          => $total,
            'page'           => $page,
            'total_pages'    => $totalPages,
            'payment_labels' => array_map(static fn (PaymentMethod $m) => $m->label(), $this->payments->all()),
        ]);
    }

    public function show(ServerRequestInterface $request, int $id): ResponseInterface
    {
        $order = $this->orders->find($id) ?? throw HttpException::notFound('Order not found.');

        return $this->responder->view($request, 'admin/order.html.twig', [
            'order'          => $order,
            'next_statuses'  => $this->service->nextStatuses($order),
            'can'            => [
                'mark_paid'     => $this->service->canMarkPaid($order),
                'cancel'        => $this->service->canCancel($order),
                'refund'        => $this->service->canRefund($order),
                'ship'          => $this->service->canShip($order),
                'edit_tracking' => $this->service->canEditTracking($order),
            ],
            'payment_label'  => $this->payments->label((string) $order['payment_method']),
            'payment_events' => $this->paymentLog->forOrder($id),
        ]);
    }

    public function handle(ServerRequestInterface $request, int $id): ResponseInterface
    {
        $this->orders->find($id) ?? throw HttpException::notFound('Order not found.');
        $body = (array) $request->getParsedBody();
        $tracking = mb_substr(trim((string) ($body['tracking_number'] ?? '')), 0, 100);
        $carrier = mb_substr(trim((string) ($body['carrier'] ?? '')), 0, 100);

        $restock = !empty($body['restock']);

        try {
            switch ((string) ($body['action'] ?? '')) {
                case 'set_status':
                    $this->service->setStatus($id, (string) ($body['status'] ?? ''));
                    $this->session->flash('success', $this->translator->trans('Status updated.'));
                    break;
                case 'mark_paid':
                    $this->service->markPaid($id);
                    $this->session->flash('success', $this->translator->trans('Payment recorded — order marked as paid.'));
                    break;
                case 'cancel':
                    $this->service->cancel($id);
                    $this->session->flash('success', $this->translator->trans('Order cancelled and its stock put back.'));
                    break;
                case 'refund':
                    $this->service->refund($id, $restock);
                    $this->session->flash('success', $restock ? $this->translator->trans('Order marked as refunded and restocked.') : $this->translator->trans('Order marked as refunded.'));
                    break;
                case 'ship':
                    $this->service->ship($id, $tracking, $carrier);
                    $this->session->flash('success', $this->translator->trans('Order marked as shipped — the customer has been emailed.'));
                    break;
                case 'tracking':
                    $this->service->updateTracking($id, $tracking, $carrier);
                    $this->session->flash('success', $this->translator->trans('Tracking details saved.'));
                    break;
                default:
                    throw new RuntimeException($this->translator->trans('Unknown action.'));
            }
        } catch (RuntimeException $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return $this->responder->redirectToRoute('admin.order', ['id' => $id]);
    }

    /** A Y-m-d date from the query string, or '' */
    private function date(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }
}
