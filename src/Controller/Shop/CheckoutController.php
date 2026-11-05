<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Http\ClientIp;
use App\Http\Responder;
use App\Http\Session;
use App\I18n\LocaleFormat;
use App\I18n\Translator;
use App\Payment\PaymentMethod;
use App\Payment\PaymentRecorder;
use App\Payment\PaymentRegistry;
use App\Repository\CustomerAddressRepository;
use App\Repository\GiftCardAttemptRepository;
use App\Repository\PageViewRepository;
use App\Security\CustomerAuthenticator;
use App\Service\CartService;
use App\Service\CheckoutService;
use App\Service\CouponService;
use App\Service\GiftCardService;
use App\Service\OrderLinks;
use App\Service\OrderNotifier;
use App\Service\OrderService;
use App\Service\ShippingService;
use App\Service\StoreSettings;
use App\Service\VatIdService;
use App\Support\Money;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

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
    private const GIFT_KEY = 'checkout_gift_card';
    /** Failed gift card codes allowed per IP and per session within the window. */
    private const GIFT_FAILS_KEY = 'checkout_gift_card_fails';
    private const GIFT_MAX_FAILS = 10;
    private const GIFT_WINDOW_MINUTES = 15;

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
        private readonly GiftCardService $giftCards,
        private readonly VatIdService $vatIds,
        private readonly GiftCardAttemptRepository $giftCardAttempts,
        private readonly ClientIp $clientIp,
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

        $giftAction = (string) ($input['gift_action'] ?? '');
        if ($giftAction === 'apply' || $giftAction === 'remove') {
            return $this->giftCardAction($request, $items, $input, $giftAction);
        }

        $customer = [];
        foreach (self::FIELDS as $field) {
            $customer[$field] = trim((string) ($input[$field] ?? ''));
        }
        $customer['vat_id'] = mb_substr(trim((string) ($input['vat_id'] ?? '')), 0, 30);
        $shippingCode = (string) ($input['shipping_method'] ?? '');
        $paymentMethod = $this->payments->enabledMethod((string) ($input['payment_method'] ?? ''));

        $errors = $this->validate($customer, $shippingCode, $paymentMethod, $this->cart->weight($items));
        if ($errors !== []) {
            return $this->form($request, $items, $input, $errors, 422);
        }

        // Signed in: the order belongs to the account. Guests stay unlinked,
        // even when the email matches an account (see CustomerAccounts).
        $customerId = $this->customerAuth->id();
        try {
            $order = $this->checkout->placeOrder($customer + ['customer_id' => $customerId], $items, $shippingCode, $paymentMethod->id(), $this->couponToUse($input), $this->giftCardToUse($input));
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
        $weight = $this->cart->weight($items);
        foreach ($this->shipping->activeMethods() as $candidate) {
            if ($candidate['code'] === $code && ($country === '' || $this->shipping->servesCountry($candidate, $country)) && $this->shipping->servesWeight($candidate, $weight)) {
                $method = $candidate;
            }
        }

        $lineTotals = $this->lineTotals($items);
        [$coupon] = $this->sessionCoupon(array_sum($lineTotals), '');
        [$giftCard] = $this->sessionGiftCard();
        $vat = $this->vatNotice((string) ($query['vat_id'] ?? ''), $country);
        $totals = $this->checkout->price($lineTotals, $method, $coupon, $country, $weight, $giftCard, $vat['reverse_charge']);

        return $this->responder->json(['success' => true, 'empty' => $items === []] + $this->summary($totals, $coupon, $giftCard) + [
            'reverse_charge' => $vat['reverse_charge'], 'vat_message' => $vat['message'], 'vat_error' => $vat['error'],
        ]);
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
    /**
     * What the VAT-number field means for the order right now (VIES lookups are cached).
     *
     * @return array{reverse_charge: bool, message: ?string, error: ?string}
     */
    private function vatNotice(string $vatId, string $country): array
    {
        try {
            $result = $this->vatIds->forCheckout($vatId, $country);

            return ['reverse_charge' => $result['reverse_charge'], 'message' => $result['reverse_charge'] ? $this->translator->trans('VAT number accepted — no VAT is charged (reverse charge).') : $result['message'], 'error' => null];
        } catch (RuntimeException $e) {
            return ['reverse_charge' => false, 'message' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * "Apply" / "Remove" next to the gift card field.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>       $input
     */
    private function giftCardAction(ServerRequestInterface $request, array $items, array $input, string $action): ResponseInterface
    {
        $typed = (string) ($input['gift_code'] ?? '');
        unset($input['gift_code']);
        if ($action === 'remove') {
            $this->session->remove(self::GIFT_KEY);

            return $this->form($request, $items, $input, [], 200, $this->translator->trans('Gift card removed.'));
        }
        // Codes must not be guessable: few failed tries per IP and per session,
        // and one answer for every reason a code can't be used.
        $ip = $this->clientIp->of($request);
        $since = time() - self::GIFT_WINDOW_MINUTES * 60;
        $failTimes = array_values(array_filter((array) $this->session->get(self::GIFT_FAILS_KEY, []), static fn ($t) => is_int($t) && $t > $since));
        $sessionFails = count($failTimes);
        try {
            $blocked = $sessionFails >= self::GIFT_MAX_FAILS
                || $this->giftCardAttempts->countRecent($ip, self::GIFT_WINDOW_MINUTES) >= self::GIFT_MAX_FAILS;
        } catch (Throwable) {
            $blocked = $sessionFails >= self::GIFT_MAX_FAILS; // store not migrated yet
        }
        if ($blocked) {
            return $this->form($request, $items, $input, [$this->translator->trans('Too many attempts. Please wait {minutes} minutes and try again.', ['minutes' => self::GIFT_WINDOW_MINUTES])], 429);
        }
        try {
            $card = $this->giftCards->usable($typed);
        } catch (RuntimeException) {
            $failTimes[] = time();
            $this->session->set(self::GIFT_FAILS_KEY, $failTimes);
            try {
                $this->giftCardAttempts->record($ip);
            } catch (Throwable) {
                // store not migrated yet: the session limit still applies
            }

            return $this->form($request, $items, $input, [$this->translator->trans('This gift card code can\'t be used. Check the code, or contact us if you think it should work.')], 422);
        }
        $this->session->remove(self::GIFT_FAILS_KEY);
        $this->session->set(self::GIFT_KEY, (string) $card['code']);

        return $this->form($request, $items, $input, [], 200, $this->translator->trans('Gift card {code} applied.', ['code' => (string) $card['code']]));
    }

    /** The gift card to pay with: the one applied in this session. */
    private function giftCardToUse(array $input): string
    {
        return (string) $this->session->get(self::GIFT_KEY, '');
    }

    /**
     * The gift card applied in this session, if it can still be used.
     *
     * @return array{0: ?array<string, mixed>, 1: ?string} card, error to show
     */
    private function sessionGiftCard(bool $forget = false): array
    {
        $code = (string) $this->session->get(self::GIFT_KEY, '');
        if ($code === '') {
            return [null, null];
        }
        try {
            return [$this->giftCards->usable($code), null];
        } catch (RuntimeException $e) {
            if ($forget) {
                $this->session->remove(self::GIFT_KEY);
            }

            return [null, $e->getMessage()];
        }
    }

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
        $this->session->remove(self::GIFT_KEY);
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
    private function summary(array $totals, ?array $coupon, ?array $giftCard = null): array
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
            'gift_card_code'     => $giftCard !== null ? (string) $giftCard['code'] : null,
            'gift_card_amount'   => (float) ($totals['gift_card'] ?? 0.0),
            'total'              => $totals['total'],
        ];
    }

    /**
     * @param array<string, string> $customer
     * @return list<string>
     */
    private function validate(array $customer, string $shippingCode, ?PaymentMethod $paymentMethod, int $weight = 0): array
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
        } elseif (!$this->shipping->servesWeight($method, $weight)) {
            $errors[] = $this->translator->trans('{method} can\'t ship an order of this weight. Please choose another shipping method.', ['method' => (string) $method['name']]);
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
        $weight = $this->cart->weight($items);
        $methods = array_values(array_filter(
            $this->shipping->activeMethods(),
            fn (array $m): bool => $this->shipping->servesWeight($m, $weight)
        ));
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
            $method['price'] = $this->checkout->shippingFor($method, $subtotal, $coupon, $weight);
            $method['countries_list'] = $this->shipping->countryList($method);
            if ($method['code'] === $form['shipping_method']) {
                $selected = $method;
            }
        }
        unset($method);

        $form['vat_id'] = mb_substr(trim((string) ($input['vat_id'] ?? '')), 0, 30);
        $vat = $this->vatNotice($form['vat_id'], $form['country']);
        [$giftCard, $giftError] = $this->sessionGiftCard(true);
        if ($giftError !== null && !in_array($giftError, $errors, true)) {
            $errors[] = $giftError . ' ' . $this->translator->trans('It has been removed from your order.');
        }
        $totals = $this->checkout->price($this->lineTotals($items), $selected, $coupon, $form['country'], $weight, $giftCard, $vat['reverse_charge']);

        return $this->responder->view($request, 'shop/checkout.html.twig', [
            'items'                  => $items,
            'subtotal'               => $subtotal,
            'shipping_methods'       => $methods,
            'payment_methods'        => array_map(
                static fn (PaymentMethod $m) => ['id' => $m->id(), 'label' => $m->label(), 'description' => $m->description()],
                array_values($payments)
            ),
            'selected_shipping_cost' => $totals['shipping'],
            'summary'                => $this->summary($totals, $coupon, $giftCard),
            'form'                   => $form,
            'errors'                 => $errors,
            'notice'                 => $notice,
            'money_format'           => Money::spec($this->store->currency(), $this->translator->intlLocale()),
            'account'                => $account,
            'saved_addresses'        => $savedAddresses,
            'vat'                    => $vat + ['enabled' => $this->vatIds->enabled()],
        ], $status);
    }
}
