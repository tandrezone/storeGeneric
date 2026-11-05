<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Exception\HttpException;
use App\Http\Responder;
use App\Http\Session;
use App\I18n\Translator;
use App\Payment\PaymentMethod;
use App\Payment\PaymentRegistry;
use App\Repository\OrderNoteRepository;
use App\Repository\OrderRepository;
use App\Repository\PaymentRepository;
use App\Security\AdminAuthenticator;
use App\Service\InvoiceService;
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
        private readonly OrderNoteRepository $notes,
        private readonly AdminAuthenticator $auth,
        private readonly InvoiceService $invoices,
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
                'provider_refund' => $this->service->canRefundAtProvider($order),
                'ship'          => $this->service->canShip($order),
                'edit_tracking' => $this->service->canEditTracking($order),
            ],
            'payment_label'  => $this->payments->label((string) $order['payment_method']),
            'payment_events' => $this->paymentLog->forOrder($id),
            'notes'          => $this->notes->forOrder($id),
            'invoice'        => $this->invoices->canInvoice($order),
            'credit_note'    => $this->invoices->canCreditNote($order),
        ]);
    }

    /** Most orders one bulk action or packing-slip print may cover. */
    private const BULK_MAX = 100;

    /** Bulk actions from the orders list: mark processing / completed / shipped for the ticked orders. */
    public function bulk(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $ids = $this->ids($body['ids'] ?? []);
        $action = (string) ($body['bulk_action'] ?? '');
        $back = $this->responder->redirectToRoute('admin.orders', [], array_filter(['status' => $body['filter_status'] ?? null]));

        if ($ids === [] || !in_array($action, ['processing', 'completed', 'ship'], true)) {
            $this->session->flash('error', $this->translator->trans('Tick some orders and choose an action.'));

            return $back;
        }
        $carrier = mb_substr(trim((string) ($body['carrier'] ?? '')), 0, 100);
        $done = 0;
        $problems = [];
        foreach ($ids as $id) {
            $order = $this->orders->find($id);
            if ($order === null) {
                continue;
            }
            try {
                $action === 'ship' ? $this->service->ship($id, '', $carrier) : $this->service->setStatus($id, $action);
                $done++;
            } catch (RuntimeException $e) {
                $problems[] = $order['order_number'] . ': ' . $e->getMessage();
            }
        }
        if ($done > 0) {
            $this->session->flash('success', $this->translator->transPlural('{count} order updated.', $done));
        }
        if ($problems !== []) {
            $this->session->flash('error', $this->translator->trans('Some orders were skipped:') . ' ' . implode(' · ', array_slice($problems, 0, 5)) . (count($problems) > 5 ? ' …' : ''));
        }

        return $back;
    }

    /** Printable packing slips for ?ids[]=… (what to pick, where to send it). */
    public function packingSlips(ServerRequestInterface $request): ResponseInterface
    {
        $ids = $this->ids($request->getQueryParams()['ids'] ?? []);
        $orders = array_values(array_filter(array_map(fn (int $id): ?array => $this->orders->find($id), $ids)));
        if ($orders === []) {
            $this->session->flash('error', $this->translator->trans('Tick some orders and choose an action.'));

            return $this->responder->redirectToRoute('admin.orders');
        }

        return $this->responder->view($request, 'admin/packing-slips.html.twig', [
            'orders' => $orders,
            'skus'   => $this->orders->itemSkus(array_map(static fn (array $o): int => (int) $o['id'], $orders)),
        ]);
    }

    /** @return list<int> distinct positive ids, at most BULK_MAX */
    private function ids(mixed $raw): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($raw) ? $raw : []), static fn (int $id): bool => $id > 0)));

        return array_slice($ids, 0, self::BULK_MAX);
    }

    /** Invoice (or ?type=credit_note) PDF for an order. */
    public function invoice(ServerRequestInterface $request, int $id): ResponseInterface
    {
        $order = $this->orders->find($id) ?? throw HttpException::notFound('Order not found.');
        $kind = ($request->getQueryParams()['type'] ?? '') === 'credit_note' ? InvoiceService::CREDIT_NOTE : InvoiceService::INVOICE;
        try {
            $pdf = $this->invoices->pdf($order, $kind);
        } catch (RuntimeException $e) {
            $this->session->flash('error', $e->getMessage());

            return $this->responder->redirectToRoute('admin.order', ['id' => $id]);
        }

        return $this->responder->downloadContent($pdf['content'], $pdf['filename'], 'application/pdf');
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
                    $viaProvider = !empty($body['provider_refund']);
                    $this->service->refund($id, $restock, $viaProvider);
                    $this->session->flash('success', $viaProvider ? $this->translator->trans('Money refunded at the provider and order marked as refunded.') : ($restock ? $this->translator->trans('Order marked as refunded and restocked.') : $this->translator->trans('Order marked as refunded.')));
                    break;
                case 'ship':
                    $this->service->ship($id, $tracking, $carrier);
                    $this->session->flash('success', $this->translator->trans('Order marked as shipped — the customer has been emailed.'));
                    break;
                case 'tracking':
                    $this->service->updateTracking($id, $tracking, $carrier);
                    $this->session->flash('success', $this->translator->trans('Tracking details saved.'));
                    break;
                case 'add_note':
                    $note = trim((string) ($body['note'] ?? ''));
                    if ($note === '') {
                        throw new RuntimeException($this->translator->trans('Write the note first.'));
                    }
                    $this->notes->add($id, ($this->auth->user()['username'] ?? null), $note);
                    $this->session->flash('success', $this->translator->trans('Note added.'));
                    break;
                case 'delete_note':
                    $this->notes->delete((int) ($body['note_id'] ?? 0), $id);
                    $this->session->flash('success', $this->translator->trans('Note deleted.'));
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
