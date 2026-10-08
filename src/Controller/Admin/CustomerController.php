<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Exception\HttpException;
use App\Http\Responder;
use App\Http\Session;
use App\I18n\Translator;
use App\Payment\PaymentMethod;
use App\Payment\PaymentRegistry;
use App\Repository\CustomerAddressRepository;
use App\Repository\CustomerRepository;
use App\Repository\OrderRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin → Customers (managers and owners): storefront accounts with their
 * order count and total spent, and per customer their orders, saved
 * addresses and a deactivate / activate switch (a deactivated customer is
 * signed out on their next request and can't sign in; guest checkout with
 * the same email still works).
 */
final class CustomerController
{
    private const PER_PAGE = 25;
    private const STATUSES = ['active', 'inactive', 'deleted'];

    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly CustomerRepository $customers,
        private readonly CustomerAddressRepository $addresses,
        private readonly OrderRepository $orders,
        private readonly PaymentRegistry $payments,
        private readonly Translator $translator,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $status = (string) ($query['status'] ?? '');
        $filters = [
            'q'      => mb_substr(trim((string) ($query['q'] ?? '')), 0, 100),
            'status' => in_array($status, self::STATUSES, true) ? $status : null,
        ];

        $total = $this->customers->countForAdmin($filters);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($totalPages, max(1, (int) ($query['page'] ?? 1)));

        return $this->responder->view($request, 'admin/customers.html.twig', [
            'customers'   => $this->customers->pageForAdmin($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'filters'     => $filters,
            'statuses'    => self::STATUSES,
            'total'       => $total,
            'page'        => $page,
            'total_pages' => $totalPages,
        ]);
    }

    public function show(ServerRequestInterface $request, int $id): ResponseInterface
    {
        $customer = $this->customers->find($id) ?? throw HttpException::notFound('Customer not found.');
        unset($customer['password_hash']);

        return $this->responder->view($request, 'admin/customer.html.twig', [
            'customer'       => $customer,
            'stats'          => $this->customers->stats($id),
            'orders'         => $this->orders->pageForCustomer($id, 100),
            'addresses'      => $this->addresses->forCustomer($id),
            'payment_labels' => array_map(static fn (PaymentMethod $m) => $m->label(), $this->payments->all()),
        ]);
    }

    public function handle(ServerRequestInterface $request, int $id): ResponseInterface
    {
        $customer = $this->customers->find($id) ?? throw HttpException::notFound('Customer not found.');
        $body = (array) $request->getParsedBody();

        switch ((string) ($body['action'] ?? '')) {
            case 'set_active':
                if ($customer['deleted_at'] !== null) {
                    $this->session->flash('error', $this->translator->trans('This account was deleted by the customer and can\'t be reactivated.'));
                    break;
                }
                $active = !empty($body['active']);
                $this->customers->setActive($id, $active);
                $this->session->flash('success', $active
                    ? $this->translator->trans('{email} can sign in again.', ['email' => $customer['email']])
                    : $this->translator->trans('{email} is deactivated and signed out. Their orders are unchanged.', ['email' => $customer['email']]));
                break;

            default:
                throw HttpException::badRequest('Unknown action.');
        }

        return $this->responder->redirectToRoute('admin.customer', ['id' => $id]);
    }
}
