<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Http\Responder;
use App\Http\Router;
use App\Http\Session;
use App\Payment\PaymentMethod;
use App\Payment\PaymentRegistry;
use App\Repository\PageViewRepository;
use App\Service\CartService;
use App\Service\CheckoutService;
use App\Service\ShippingService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/** Checkout form → order → payment provider (or confirmation page for offline methods). */
final class CheckoutController
{
    private const FIELDS = ['name', 'email', 'phone', 'address1', 'address2', 'city', 'state', 'postal_code', 'country'];

    public function __construct(
        private readonly Responder $responder,
        private readonly Router $router,
        private readonly CartService $cart,
        private readonly CheckoutService $checkout,
        private readonly ShippingService $shipping,
        private readonly PaymentRegistry $payments,
        private readonly PageViewRepository $pageViews,
        private readonly Session $session,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $items = $this->cart->items();
        if ($items === []) {
            return $this->responder->redirectToRoute('cart');
        }

        $this->pageViews->record($this->session->id(), 'checkout_shipping', null, $request->getUri()->getPath());

        return $this->form($request, $items, [], []);
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        $items = $this->cart->items();
        if ($items === []) {
            return $this->responder->redirectToRoute('cart');
        }

        $input = (array) $request->getParsedBody();
        $customer = [];
        foreach (self::FIELDS as $field) {
            $customer[$field] = trim((string) ($input[$field] ?? ''));
        }
        $shippingCode = (string) ($input['shipping_method'] ?? '');
        $paymentMethod = $this->payments->enabledMethod((string) ($input['payment_method'] ?? ''));

        $errors = $this->validate($customer, $shippingCode, $paymentMethod);
        if ($errors !== []) {
            return $this->form($request, $items, $input, $errors, 422);
        }

        try {
            $order = $this->checkout->placeOrder($customer, $items, $shippingCode, $paymentMethod->id());
        } catch (RuntimeException $e) {
            return $this->form($request, $items, $input, [$e->getMessage()], 422);
        }

        try {
            $redirect = $paymentMethod->start($order)
                ?? $this->router->url('order.confirmation', [], ['order' => $order['order_number']]);
        } catch (\Throwable $e) {
            $this->logger->error('Payment start failed', ['order' => $order['order_number'], 'method' => $paymentMethod->id(), 'error' => $e->getMessage()]);

            return $this->form($request, $items, $input, [
                'Could not start the payment with ' . $paymentMethod->label() . '. Please try again or choose another payment method.',
            ], 502);
        }

        $this->cart->clear();
        $this->pageViews->record($this->session->id(), 'checkout_payment', null, $request->getUri()->getPath());

        return $this->responder->redirect($redirect);
    }

    /**
     * @param array<string, string> $customer
     * @return list<string>
     */
    private function validate(array $customer, string $shippingCode, ?PaymentMethod $paymentMethod): array
    {
        $errors = [];
        if ($customer['name'] === '') {
            $errors[] = 'Full name is required.';
        }
        if (!filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email is required.';
        }
        foreach (['address1' => 'Address', 'city' => 'City', 'postal_code' => 'Postal code', 'country' => 'Country'] as $field => $label) {
            if ($customer[$field] === '') {
                $errors[] = "{$label} is required.";
            }
        }

        $method = null;
        foreach ($this->shipping->activeMethods() as $candidate) {
            if ($candidate['code'] === $shippingCode) {
                $method = $candidate;
            }
        }
        if ($method === null) {
            $errors[] = 'Please choose a shipping method.';
        } elseif ($customer['country'] !== '' && !$this->shipping->servesCountry($method, $customer['country'])) {
            $errors[] = "{$method['name']} doesn't deliver to {$customer['country']}. Please choose another shipping method.";
        }

        if ($paymentMethod === null) {
            $errors[] = 'Please choose a payment method.';
        }

        return $errors;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>       $input
     * @param list<string>               $errors
     */
    private function form(ServerRequestInterface $request, array $items, array $input, array $errors, int $status = 200): ResponseInterface
    {
        $subtotal = $this->cart->total($items);
        $methods = $this->shipping->activeMethods();
        $payments = $this->payments->enabled();

        if ($methods === []) {
            $errors[] = 'Checkout is unavailable: no shipping methods are set up yet.';
        }
        if ($payments === []) {
            $errors[] = 'Checkout is unavailable: no payment methods are enabled yet.';
        }

        $form = array_fill_keys(self::FIELDS, '');
        foreach (self::FIELDS as $field) {
            $form[$field] = (string) ($input[$field] ?? '');
        }
        $form['shipping_method'] = (string) ($input['shipping_method'] ?? ($methods[0]['code'] ?? ''));
        $form['payment_method'] = (string) ($input['payment_method'] ?? (array_key_first($payments) ?? ''));

        $selectedCost = 0.0;
        foreach ($methods as &$method) {
            $method['price'] = $this->shipping->priceFor($method, $subtotal);
            $method['countries_list'] = $this->shipping->countryList($method);
            if ($method['code'] === $form['shipping_method']) {
                $selectedCost = $method['price'];
            }
        }
        unset($method);

        return $this->responder->view($request, 'shop/checkout.html.twig', [
            'items'                  => $items,
            'subtotal'               => $subtotal,
            'shipping_methods'       => $methods,
            'payment_methods'        => array_map(
                static fn (PaymentMethod $m) => ['id' => $m->id(), 'label' => $m->label(), 'description' => $m->description()],
                array_values($payments)
            ),
            'selected_shipping_cost' => $selectedCost,
            'form'                   => $form,
            'errors'                 => $errors,
        ], $status);
    }
}
