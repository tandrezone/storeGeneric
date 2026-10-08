<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Http\Responder;
use App\Http\Session;
use App\I18n\LocaleFormat;
use App\I18n\Translator;
use App\Payment\PaymentMethod;
use App\Payment\PaymentRecorder;
use App\Payment\PaymentRegistry;
use App\Repository\CustomerAddressRepository;
use App\Repository\PageViewRepository;
use App\Security\CustomerAuthenticator;
use App\Service\CartService;
use App\Service\CheckoutService;
use App\Service\CouponService;
use App\Service\OrderLinks;
use App\Service\OrderNotifier;
use App\Service\OrderService;
use App\Service\ShippingService;
use App\Service\StoreSettings;
use App\Support\Money;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Checkout form → order → payment provider (or confirmation page for offline
 * methods). The applied discount code is kept in the session; "Apply" and
 * "Remove" post the form back to itself so they work without JavaScript, and
 * checkout.js refreshes the totals (shipping, discount, VAT) from quote().
 */
final class CheckoutController
{
    private const FIELDS = ['name', 'email', 'phone', 'address1', 'address2', 'city', 'state', 'postal_code', 'country'];
    private const COUPON_KEY = 'checkout_coupon';

    public function __construct(
        private readonly Responder $responder,
        private readonly StoreSettings $store,
        private readonly CartService $cart,
        private readonly CheckoutService $checkout,
        private readonly ShippingService $shipping,
        private readonly PaymentRegistry $payments,
        private readonly PageViewRepository $pageViews,
        private readonly Session $session,
        private readonly OrderLinks $links,
        private readonly OrderService $orderService,
        private readonly OrderNotifier $notifier,
        private readonly CouponService $coupons,
        private readonly PaymentRecorder $recorder,
        private readonly LoggerInterface $logger,
        private readonly CustomerAuthenticator $customerAuth,
        private readonly CustomerAddressRepository $customerAddresses,
        private readonly Translator $translator,
    ) {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $removed = $this->cart->removeUnavailable();
        $notices = $removed === [] ? [] : [$this->translator->trans('No longer available and removed from your cart: {products}.', ['products' => implode(', ', $removed)])];
        $items = $this->cart->items();
        if ($items === []) {
            foreach ($notices as $notice) {
                $this->session->flash('error', $notice);
            }

            return $this->responder->redirectToRoute('cart');
        }

        $this->pageViews->record($this->session->id(), 'checkout_shipping', null, $request->getUri()->getPath());

        return $this->form($request, $items, [], $notices);
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        $items = $this->cart->items();
        if ($items === []) {
            return $this->responder->redirectToRoute('cart');
        }

        $input = (array) $request->getParsedBody();
        $couponAction = (string) ($input['coupon_action'] ?? '');
        if ($couponAction === 'apply' || $couponAction === 'remove') {
            return $this->couponAction($request, $items, $input, $couponAction);
        }

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

        // Signed in: the order belongs to the account. Guests stay unlinked,
        // even when the email matches an account (see CustomerAccounts).
        $customerId = $this->customerAuth->id();
        try {
            $order = $this->checkout->placeOrder($customer + ['customer_id' => $customerId], $items, $shippingCode, $paymentMethod->id(), $this->couponToUse($input));
        } catch (RuntimeException $e) {
            // Show current prices / stock with the error.
            return $this->form($request, $this->cart->items() ?: $items, $input, [$e->getMessage()], 422);
        }
        if ($customerId !== null && !empty($input['save_address'])) {
            $this->saveAddress($customerId, $customer);
        }

        if ($order['total'] <= 0.0) {
            // Nothing to pay (e.g. a 100% discount with free shipping): don't send the customer to a provider.
            return $this->finish($request, $order, $this->paidInFull($order));
        }

        try {
            $redirect = $paymentMethod->start($order) ?? $this->links->confirmationPath($order['order_number']);
        } catch (\Throwable $e) {
            $this->logger->error('Payment start failed', ['order' => $order['order_number'], 'method' => $paymentMethod->id(), 'error' => $e->getMessage()]);
            // The customer never reached the provider: drop the order and give its stock back.
            try {
                $this->orderService->cancelUnstarted($order['id']);
            } catch (\Throwable $cancelError) {
                $this->logger->error('Could not cancel the unstarted order', ['order' => $order['order_number'], 'error' => $cancelError->getMessage()]);
            }

            return $this->form($request, $items, $input, [
                $this->translator->trans('Could not start the payment with {method}. Please try again or choose another payment method.', ['method' => $paymentMethod->label()]),
            ], 502);
        }

        $this->notifier->orderPlaced($order['id']);

        return $this->finish($request, $order, $redirect);
    }

    /**
     * Live totals for checkout.js: GET ?country=…&shipping_method=… → shipping,
     * discount (the code applied in the session), VAT and total for the cart.
     */
    public function quote(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $country = mb_substr(trim((string) ($query['country'] ?? '')), 0, 100);
        $code = (string) ($query['shipping_method'] ?? '');
        $items = $this->cart->items();

        $method = null;
        foreach ($this->shipping->activeMethods() as $candidate) {
            if ($candidate['code'] === $code && ($country === '' || $this->shipping->servesCountry($candidate, $country))) {
                $method = $candidate;
            }
        }

        $lineTotals = $this->lineTotals($items);
        [$coupon] = $this->sessionCoupon(array_sum($lineTotals), '');
        $totals = $this->checkout->price($lineTotals, $method, $coupon, $country);

        return $this->responder->json(['success' => true, 'empty' => $items === []] + $this->summary($totals, $coupon));
    }

    /**
     * "Apply" / "Remove" next to the discount code field.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>       $input
     */
    private function couponAction(ServerRequestInterface $request, array $items, array $input, string $action): ResponseInterface
    {
        if ($action === 'remove') {
            $this->session->remove(self::COUPON_KEY);
            unset($input['coupon_code']);

            return $this->form($request, $items, $input, [], 200, $this->translator->trans('Discount code removed.'));
        }

        $email = trim((string) ($input['email'] ?? ''));
        try {
            $coupon = $this->coupons->usable(
                (string) ($input['coupon_code'] ?? ''),
                $this->cart->total($items),
                filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : ''
            );
        } catch (RuntimeException $e) {
            return $this->form($request, $items, $input, [$e->getMessage()], 422);
        }
        $this->session->set(self::COUPON_KEY, (string) $coupon['code']);
        unset($input['coupon_code']);

        return $this->form($request, $items, $input, [], 200, $this->translator->trans('Discount code {code} applied: {discount}.', [
            'code'     => (string) $coupon['code'],
            'discount' => $this->coupons->describe($coupon),
        ]));
    }

    /**
     * The code to place the order with: one typed but not applied yet wins
     * over the one in the session ('' = none).
     *
     * @param array<string, mixed> $input
     */
    private function couponToUse(array $input): string
    {
        $typed = CouponService::normalize((string) ($input['coupon_code'] ?? ''));

        return $typed !== '' ? $typed : (string) $this->session->get(self::COUPON_KEY, '');
    }

    /**
     * The applied code if it still applies to this subtotal (and email). With
     * $forget, a code that no longer does is dropped from the session.
     *
     * @return array{0: array<string, mixed>|null, 1: ?string} coupon, reason it doesn't apply
     */
    private function sessionCoupon(float $subtotal, string $email, bool $forget = false): array
    {
        $code = (string) $this->session->get(self::COUPON_KEY, '');
        if ($code === '') {
            return [null, null];
        }
        try {
            return [$this->coupons->usable($code, $subtotal, $email), null];
        } catch (RuntimeException $e) {
            if ($forget) {
                $this->session->remove(self::COUPON_KEY);
            }

            return [null, $e->getMessage()];
        }
    }

    /**
     * "Save this address to my account" (signed in): adds the shipping
     * address unless it is saved already; the first one becomes the default.
     * Never blocks the order.
     *
     * @param array<string, string> $customer checkout fields
     */
    private function saveAddress(int $customerId, array $customer): void
    {
        try {
            if (!$this->customerAddresses->exists($customerId, $customer)) {
                $this->customerAddresses->create($customerId, $customer);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Could not save the checkout address', ['customer' => $customerId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Account details for the form: the signed-in customer, their saved
     * addresses and (on first view) the fields prefilled from the default
     * address, or from ?address=<id> ("Use this address" without JavaScript).
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null, 2: list<array<string, mixed>>}
     */
    private function accountPrefill(ServerRequestInterface $request, array $form, array $input): array
    {
        $account = $this->customerAuth->customer();
        if ($account === null) {
            return [$form, null, []];
        }
        $saved = $this->customerAddresses->forCustomer((int) $account['id']);
        $form['save_address'] = $input === [] ? $saved === [] : !empty($input['save_address']);
        if ($input !== []) {
            return [$form, $account, $saved];
        }

        $wanted = (int) ($request->getQueryParams()['address'] ?? 0);
        $address = $saved[0] ?? null;
        foreach ($saved as $candidate) {
            if ((int) $candidate['id'] === $wanted) {
                $address = $candidate;
            }
        }
        $form['name'] = (string) ($address['name'] ?? $account['name']);
        $form['email'] = (string) $account['email'];
        $form['phone'] = (string) ($address['phone'] ?? $account['phone'] ?? '');
        foreach (['address1', 'address2', 'city', 'state', 'postal_code', 'country'] as $field) {
            $form[$field] = (string) ($address[$field] ?? '');
        }
        $form['address_id'] = $address !== null ? (int) $address['id'] : 0;

        return [$form, $account, $saved];
    }

    /**
     * Records a zero-total order as paid straight away.
     *
     * @param array{id: int, order_number: string, total: float, email: string} $order
     */
    private function paidInFull(array $order): string
    {
        $this->recorder->record($order['order_number'], 'free', 'paid', 'no-payment-due', 0.0, $this->store->currency());

        return $this->links->confirmationPath($order['order_number']);
    }

    /** @param array{id: int, order_number: string, total: float, email: string} $order */
    private function finish(ServerRequestInterface $request, array $order, string $redirect): ResponseInterface
    {
        $this->links->rememberPlaced($order['order_number']);
        $this->session->remove(self::COUPON_KEY);
        $this->cart->clear();
        $this->pageViews->record($this->session->id(), 'checkout_payment', null, $request->getUri()->getPath());

        return $this->responder->redirect($redirect);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<float>
     */
    private function lineTotals(array $items): array
    {
        return array_map(static fn (array $item): float => round((float) $item['price'] * (int) $item['quantity'], 2), $items);
    }

    /**
     * What the order summary shows (page and quote JSON).
     *
     * @param array<string, mixed>      $totals CheckoutService::price()
     * @param array<string, mixed>|null $coupon
     * @return array<string, mixed>
     */
    private function summary(array $totals, ?array $coupon): array
    {
        $rate = (float) $totals['tax_rate'];
        // "23" / "6,5": only the decimals the rate needs, in the visitor's number format.
        $rateDecimals = strlen(rtrim(substr(number_format($rate, 2, '.', ''), -2), '0'));
        $rateText = LocaleFormat::number($rate, $this->translator->intlLocale(), $rateDecimals);

        return [
            'subtotal'           => $totals['subtotal'],
            'shipping'           => $totals['shipping'],
            'discount'           => $totals['discount'],
            'coupon_code'        => $coupon !== null ? (string) $coupon['code'] : null,
            'coupon_description' => $coupon !== null ? $this->coupons->describe($coupon) : null,
            'tax_rate'           => $rate,
            'tax_amount'         => $totals['tax_amount'],
            'prices_include_tax' => $totals['prices_include_tax'],
            'tax_label'          => $rate > 0.0
                ? $this->translator->trans($totals['prices_include_tax'] ? 'Includes VAT ({rate}%)' : 'VAT ({rate}%)', ['rate' => $rateText])
                : null,
            'total'              => $totals['total'],
        ];
    }

    /**
     * @param array<string, string> $customer
     * @return list<string>
     */
    private function validate(array $customer, string $shippingCode, ?PaymentMethod $paymentMethod): array
    {
        $errors = [];
        if ($customer['name'] === '') {
            $errors[] = $this->translator->trans('Full name is required.');
        }
        if (!filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = $this->translator->trans('A valid email is required.');
        }
        $required = [
            'address1'    => 'Address is required.',
            'city'        => 'City is required.',
            'postal_code' => 'Postal code is required.',
            'country'     => 'Country is required.',
        ];
        foreach ($required as $field => $message) {
            if ($customer[$field] === '') {
                $errors[] = $this->translator->trans($message);
            }
        }

        $method = null;
        foreach ($this->shipping->activeMethods() as $candidate) {
            if ($candidate['code'] === $shippingCode) {
                $method = $candidate;
            }
        }
        if ($method === null) {
            $errors[] = $this->translator->trans('Please choose a shipping method.');
        } elseif ($customer['country'] !== '' && !$this->shipping->servesCountry($method, $customer['country'])) {
            $errors[] = $this->translator->trans('{method} doesn\'t deliver to {country}. Please choose another shipping method.', [
                'method'  => (string) $method['name'],
                'country' => $customer['country'],
            ]);
        }

        if ($paymentMethod === null) {
            $errors[] = $this->translator->trans('Please choose a payment method.');
        }

        return $errors;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>       $input
     * @param list<string>               $errors
     */
    private function form(ServerRequestInterface $request, array $items, array $input, array $errors, int $status = 200, ?string $notice = null): ResponseInterface
    {
        $subtotal = $this->cart->total($items);
        $methods = $this->shipping->activeMethods();
        $payments = $this->payments->enabled();

        if ($methods === []) {
            $errors[] = $this->translator->trans('Checkout is unavailable: no shipping methods are set up yet.');
        }
        if ($payments === []) {
            $errors[] = $this->translator->trans('Checkout is unavailable: no payment methods are enabled yet.');
        }

        $form = array_fill_keys(self::FIELDS, '');
        foreach (self::FIELDS as $field) {
            $form[$field] = (string) ($input[$field] ?? '');
        }
        $form['shipping_method'] = (string) ($input['shipping_method'] ?? ($methods[0]['code'] ?? ''));
        $form['payment_method'] = (string) ($input['payment_method'] ?? (array_key_first($payments) ?? ''));

        $form['coupon_code'] = (string) ($input['coupon_code'] ?? '');
        $form['address_id'] = (int) ($input['address_id'] ?? 0);
        [$form, $account, $savedAddresses] = $this->accountPrefill($request, $form, $input);

        $email = filter_var($form['email'], FILTER_VALIDATE_EMAIL) ? $form['email'] : '';
        [$coupon, $couponError] = $this->sessionCoupon($subtotal, $email, true);
        if ($couponError !== null && !in_array($couponError, $errors, true)) {
            $errors[] = $couponError . ' ' . $this->translator->trans('It has been removed from your order.');
        }

        // Priced after the discount, like the order itself (a free-shipping threshold counts the discounted subtotal).
        $selected = null;
        foreach ($methods as &$method) {
            $method['price'] = $this->checkout->shippingFor($method, $subtotal, $coupon);
            $method['countries_list'] = $this->shipping->countryList($method);
            if ($method['code'] === $form['shipping_method']) {
                $selected = $method;
            }
        }
        unset($method);

        $totals = $this->checkout->price($this->lineTotals($items), $selected, $coupon, $form['country']);

        return $this->responder->view($request, 'shop/checkout.html.twig', [
            'items'                  => $items,
            'subtotal'               => $subtotal,
            'shipping_methods'       => $methods,
            'payment_methods'        => array_map(
                static fn (PaymentMethod $m) => ['id' => $m->id(), 'label' => $m->label(), 'description' => $m->description()],
                array_values($payments)
            ),
            'selected_shipping_cost' => $totals['shipping'],
            'summary'                => $this->summary($totals, $coupon),
            'form'                   => $form,
            'errors'                 => $errors,
            'notice'                 => $notice,
            'money_format'           => Money::spec($this->store->currency(), $this->translator->intlLocale()),
            'account'                => $account,
            'saved_addresses'        => $savedAddresses,
        ], $status);
    }
}
