<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Http\Exception\HttpException;
use App\Http\Responder;
use App\Http\Session;
use App\Payment\PaymentRegistry;
use App\Repository\CustomerAddressRepository;
use App\Repository\OrderRepository;
use App\Security\CustomerAuthenticator;
use App\Service\CustomerAccounts;
use App\Service\OrderLinks;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * The signed-in customer's account area: overview, own orders, profile
 * (name/phone, email, password, delete account) and saved addresses.
 * Every page needs a customer session (otherwise → sign-in page with
 * ?next=), and orders are only ever looked up by orders.customer_id.
 */
final class AccountController
{
    private const ORDERS_PER_PAGE = 20;

    public function __construct(
        private readonly Responder $responder,
        private readonly Session $session,
        private readonly CustomerAuthenticator $auth,
        private readonly CustomerAccounts $accounts,
        private readonly CustomerAddressRepository $addresses,
        private readonly OrderRepository $orders,
        private readonly PaymentRegistry $payments,
        private readonly OrderLinks $links,
    ) {
    }

    public function overview(ServerRequestInterface $request): ResponseInterface
    {
        $customer = $this->auth->customer();
        if ($customer === null) {
            return $this->toLogin($request);
        }
        $id = (int) $customer['id'];

        return $this->responder->view($request, 'shop/account/overview.html.twig', [
            'customer'        => $customer,
            'recent_orders'   => $this->orders->pageForCustomer($id, 5),
            'order_count'     => $this->orders->countForCustomer($id),
            'default_address' => $this->addresses->defaultFor($id),
            'payment_labels'  => $this->paymentLabels(),
        ]);
    }

    public function orders(ServerRequestInterface $request): ResponseInterface
    {
        $customer = $this->auth->customer();
        if ($customer === null) {
            return $this->toLogin($request);
        }
        $id = (int) $customer['id'];
        $total = $this->orders->countForCustomer($id);
        $totalPages = max(1, (int) ceil($total / self::ORDERS_PER_PAGE));
        $page = min($totalPages, max(1, (int) ($request->getQueryParams()['page'] ?? 1)));

        return $this->responder->view($request, 'shop/account/orders.html.twig', [
            'customer'       => $customer,
            'orders'         => $this->orders->pageForCustomer($id, self::ORDERS_PER_PAGE, ($page - 1) * self::ORDERS_PER_PAGE),
            'total'          => $total,
            'page'           => $page,
            'total_pages'    => $totalPages,
            'payment_labels' => $this->paymentLabels(),
        ]);
    }

    /** One of the customer's own orders; anyone else's (or an unknown number) is a 404. */
    public function order(ServerRequestInterface $request, string $number): ResponseInterface
    {
        $customer = $this->auth->customer();
        if ($customer === null) {
            return $this->toLogin($request);
        }
        $order = $this->orders->findByNumberForCustomer(strtoupper($number), (int) $customer['id'])
            ?? throw HttpException::notFound('Order not found.');
        $method = $this->payments->get((string) $order['payment_method']);

        return $this->responder->view($request, 'shop/account/order.html.twig', [
            'customer'             => $customer,
            'order'                => $order,
            'payment_label'        => $this->payments->label((string) $order['payment_method']),
            'payment_instructions' => $method !== null && $method->isOffline() && $order['payment_status'] === 'unpaid' && $order['status'] === 'pending'
                ? $method->instructions($order) : '',
            'track_url'            => $this->links->trackUrl((string) $order['order_number']),
        ]);
    }

    public function profile(ServerRequestInterface $request): ResponseInterface
    {
        $customer = $this->auth->customer();
        if ($customer === null) {
            return $this->toLogin($request);
        }

        return $this->profilePage($request, $customer);
    }

    /** One of the profile forms, by `action`: profile, email, password or delete. */
    public function updateProfile(ServerRequestInterface $request): ResponseInterface
    {
        $customer = $this->auth->customer();
        if ($customer === null) {
            return $this->toLogin($request);
        }
        $id = (int) $customer['id'];
        $body = (array) $request->getParsedBody();
        $action = (string) ($body['action'] ?? '');

        try {
            switch ($action) {
                case 'profile':
                    $this->accounts->updateProfile($id, $body);
                    $this->session->flash('success', 'Your details have been saved.');
                    break;

                case 'email':
                    if ($this->accounts->changeEmail($id, $body)) {
                        $this->session->flash('success', 'Your email address has been changed. We sent a link to the new address to confirm it.');
                    }
                    break;

                case 'password':
                    $this->accounts->changePassword($id, $body);
                    $this->session->flash('success', 'Your password has been changed. Other devices have been signed out.');
                    break;

                case 'delete':
                    if (empty($body['confirm'])) {
                        throw new RuntimeException('Tick the box to confirm that you want to delete your account.');
                    }
                    $this->accounts->deleteAccount($id, (string) ($body['current_password'] ?? ''));
                    $this->auth->logout();
                    $this->links->forgetPlaced();
                    $this->session->flash('success', 'Your account has been deleted. Your past orders are kept for our records.');

                    return $this->responder->redirectToRoute('account.login');

                default:
                    throw HttpException::badRequest('Unknown action.');
            }
        } catch (HttpException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return $this->profilePage($request, $this->auth->customer() ?? $customer, [$action => $e->getMessage()], $body, 422);
        }

        return $this->responder->redirectToRoute('account.profile');
    }

    public function addresses(ServerRequestInterface $request): ResponseInterface
    {
        $customer = $this->auth->customer();
        if ($customer === null) {
            return $this->toLogin($request);
        }

        return $this->addressPage($request, $customer);
    }

    /** Saved addresses, by `action`: create, update, delete or set_default. */
    public function updateAddresses(ServerRequestInterface $request): ResponseInterface
    {
        $customer = $this->auth->customer();
        if ($customer === null) {
            return $this->toLogin($request);
        }
        $customerId = (int) $customer['id'];
        $body = (array) $request->getParsedBody();
        $addressId = (int) ($body['id'] ?? 0);

        try {
            switch ((string) ($body['action'] ?? '')) {
                case 'create':
                    $this->addresses->create($customerId, $this->accounts->addressFromInput($body), !empty($body['is_default']));
                    $this->session->flash('success', 'Address saved.');
                    break;

                case 'update':
                    $address = $this->accounts->addressFromInput($body);
                    if (!$this->addresses->update($customerId, $addressId, $address)) {
                        throw HttpException::notFound('Address not found.');
                    }
                    if (!empty($body['is_default'])) {
                        $this->addresses->setDefault($customerId, $addressId);
                    }
                    $this->session->flash('success', 'Address updated.');
                    break;

                case 'delete':
                    if (!$this->addresses->delete($customerId, $addressId)) {
                        throw HttpException::notFound('Address not found.');
                    }
                    $this->session->flash('success', 'Address deleted.');
                    break;

                case 'set_default':
                    if (!$this->addresses->setDefault($customerId, $addressId)) {
                        throw HttpException::notFound('Address not found.');
                    }
                    $this->session->flash('success', 'Default address changed.');
                    break;

                default:
                    throw HttpException::badRequest('Unknown action.');
            }
        } catch (HttpException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return $this->addressPage($request, $customer, [$e->getMessage()], $body, 422);
        }

        return $this->responder->redirectToRoute('account.addresses');
    }

    private function toLogin(ServerRequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $path = $request->getMethod() === 'GET' ? $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '') : $uri->getPath();
        $this->session->flash('error', 'Please sign in to see your account.');

        return $this->responder->redirectToRoute('account.login', [], ['next' => $path]);
    }

    /**
     * @param array<string, mixed>  $customer
     * @param array<string, string> $errors form action => message
     * @param array<string, mixed>  $input
     */
    private function profilePage(ServerRequestInterface $request, array $customer, array $errors = [], array $input = [], int $status = 200): ResponseInterface
    {
        $action = (string) ($input['action'] ?? '');

        return $this->responder->view($request, 'shop/account/profile.html.twig', [
            'customer'     => $customer,
            'errors'       => $errors,
            'form'         => [
                'name'  => $action === 'profile' ? (string) ($input['name'] ?? '') : (string) $customer['name'],
                'phone' => $action === 'profile' ? (string) ($input['phone'] ?? '') : (string) ($customer['phone'] ?? ''),
                'email' => $action === 'email' ? (string) ($input['email'] ?? '') : (string) $customer['email'],
            ],
            'min_password' => CustomerAuthenticator::MIN_PASSWORD_LENGTH,
        ], $status);
    }

    /**
     * @param array<string, mixed> $customer
     * @param list<string>         $errors
     * @param array<string, mixed> $input the rejected form, shown again
     */
    private function addressPage(ServerRequestInterface $request, array $customer, array $errors = [], array $input = [], int $status = 200): ResponseInterface
    {
        return $this->responder->view($request, 'shop/account/addresses.html.twig', [
            'customer'  => $customer,
            'addresses' => $this->addresses->forCustomer((int) $customer['id']),
            'errors'    => $errors,
            'input'     => $input,
        ], $status);
    }

    /** @return array<string, string> payment method id => label */
    private function paymentLabels(): array
    {
        $labels = [];
        foreach ($this->payments->all() as $id => $method) {
            $labels[$id] = $method->label();
        }

        return $labels;
    }
}
