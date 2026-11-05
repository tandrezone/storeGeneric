<?php

declare(strict_types=1);

namespace App\Service;

use App\I18n\Translator;
use App\Infrastructure\Database;
use App\Repository\OrderRepository;
use App\Repository\VariantRepository;
use RuntimeException;

/**
 * Turns a cart into an order in one transaction: locks each variant row,
 * re-checks that it is for sale and in stock, prices the order from the
 * locked rows (items + shipping − discount, VAT via TaxCalculator), writes it,
 * reserves the stock (orders.stock_reserved = 1) and counts a use of the
 * discount code (orders.coupon_counted = 1, coupon row locked). Both go back
 * when the order is cancelled, expires or fails (OrderService / PaymentRecorder).
 */
final class CheckoutService
{
    public function __construct(
        private readonly Database $db,
        private readonly VariantRepository $variants,
        private readonly OrderRepository $orders,
        private readonly ShippingService $shipping,
        private readonly CouponService $coupons,
        private readonly TaxCalculator $tax,
        private readonly Translator $translator,
        private readonly GiftCardService $giftCards,
        private readonly VatIdService $vat,
        private readonly \App\Support\FileCache $cache,
    ) {
    }

    /**
     * Prices an order: shipping for the discounted subtotal, the coupon's
     * discount, VAT for the country. Used for the checkout summary, the quote
     * API and the order itself, so they always agree.
     *
     * @param list<float>               $lineTotals
     * @param array<string, mixed>|null $method shipping method (null = none chosen yet)
     * @param array<string, mixed>|null $coupon
     * @param array<string, mixed>|null $giftCard the card applied (null = none): `total` is then what is left after it,
     *                                            `gift_card` the amount it covers, `total_before_gift` the order's full value
     * @return array{subtotal: float, discount: float, shipping: float, prices_include_tax: bool, tax_rate: float, tax_amount: float, item_taxes: list<float>, shipping_tax: float, total: float, total_before_gift: float, gift_card: float}
     */
    public function price(array $lineTotals, ?array $method, ?array $coupon, string $country, int $weightGrams = 0, ?array $giftCard = null, bool $reverseCharge = false): array
    {
        $subtotal = round(array_sum($lineTotals), 2);
        $shipping = $method !== null ? $this->shippingFor($method, $subtotal, $coupon, $weightGrams) : 0.0;
        $discount = $coupon !== null ? $this->coupons->discount($coupon, $subtotal, $shipping) : ['items' => 0.0, 'shipping' => 0.0];

        $totals = $this->tax->totals($lineTotals, $discount['items'], $shipping, $discount['shipping'], $country, $reverseCharge);
        $gift = $giftCard !== null ? $this->giftCards->amountFor($giftCard, $totals['total']) : 0.0;

        return array_replace($totals, ['total_before_gift' => $totals['total'], 'gift_card' => $gift, 'total' => round($totals['total'] - $gift, 2)]);
    }

    /**
     * A shipping method's price before any free-shipping code. "Free over"
     * thresholds compare the subtotal after the coupon's items discount (what
     * the customer pays for the items), so a discount can drop an order below
     * the threshold.
     *
     * @param array<string, mixed>      $method
     * @param array<string, mixed>|null $coupon
     */
    public function shippingFor(array $method, float $subtotal, ?array $coupon, int $weightGrams = 0): float
    {
        $itemsDiscount = $coupon !== null ? $this->coupons->discount($coupon, $subtotal, 0.0)['items'] : 0.0;

        return $this->shipping->priceFor($method, round($subtotal - $itemsDiscount, 2), $weightGrams);
    }

    /**
     * @param array{name: string, email: string, phone: string, address1: string, address2: string, city: string, state: string, postal_code: string, country: string, customer_id?: ?int} $customer
     *        customer_id: the signed-in customer account (omitted / null = guest checkout)
     * @param list<array<string, mixed>> $items cart lines (CartService::items())
     * @param string                     $couponCode discount code ('' = none)
     * @param string                     $giftCardCode gift card to pay with ('' = none); the order total is what the card leaves over
     * @return array{id: int, order_number: string, total: float, email: string}
     * @throws RuntimeException with a message safe to show the customer
     */
    public function placeOrder(array $customer, array $items, string $shippingCode, string $paymentMethod, string $couponCode = '', string $giftCardCode = ''): array
    {
        if ($items === []) {
            throw new RuntimeException($this->translator->trans('Your cart is empty.'));
        }

        // A VAT number from an EU business: checked (cached) and, if the rule applies, no VAT. Throws when it can't be used.
        $vat = $this->vat->forCheckout((string) ($customer['vat_id'] ?? ''), (string) $customer['country']);

        $cartWeight = 0;
        foreach ($items as $item) {
            $cartWeight += (int) ($item['weight_grams'] ?? 0) * (int) $item['quantity'];
        }
        $method = $this->shipping->available($shippingCode, $customer['country'], $cartWeight)
            ?? throw new RuntimeException($this->translator->trans('That shipping method is not available for your country or order weight.'));

        // Lock in a fixed order so two checkouts with the same items can't deadlock.
        usort($items, static fn (array $a, array $b) => (int) $a['variant_id'] <=> (int) $b['variant_id']);

        $placed = $this->db->transaction(function () use ($customer, $items, $method, $paymentMethod, $couponCode, $giftCardCode, $vat): array {
            $subtotal = 0.0;
            $weight = 0;
            $lines = [];
            foreach ($items as $item) {
                $quantity = (int) $item['quantity'];
                $row = $this->variants->lockForUpdate((int) $item['variant_id']);
                if ($row === null || !(int) $row['available']) {
                    throw new RuntimeException($this->translator->trans('{product} is no longer available.', ['product' => (string) $item['product_name']]));
                }

                // A variant without a real price isn't for sale — treat it as out of stock.
                $price = (float) $row['price'];
                $available = $price <= 0.0 ? 0 : (int) $row['stock'];
                if ($quantity < 1 || $available < $quantity) {
                    throw new RuntimeException($this->translator->transPlural('Not enough stock for {product} — only {count} left.', $available, ['product' => (string) $row['product_name']]));
                }
                if (abs($price - (float) $item['price']) >= 0.005) {
                    throw new RuntimeException($this->translator->trans('The price of {product} has changed. Please review your order and place it again.', ['product' => (string) $row['product_name']]));
                }

                $lineTotal = round($price * $quantity, 2);
                $subtotal += $lineTotal;
                $weight += (int) $row['weight_grams'] * $quantity;
                $lines[] = [
                    'variant_id'   => (int) $item['variant_id'],
                    'product_name' => (string) $row['product_name'],
                    'label'        => $row['label'] !== null ? (string) $row['label'] : null,
                    'unit'         => $row['unit'] !== null ? (string) $row['unit'] : null,
                    'unit_price'   => $price,
                    'quantity'     => $quantity,
                    'line_total'   => $lineTotal,
                ];
            }

            $subtotal = round($subtotal, 2);
            // Variant rows first, then the coupon row (same lock order as OrderService / PaymentRecorder).
            $coupon = $couponCode !== '' ? $this->coupons->claim($couponCode, $subtotal, $customer['email']) : null;
            $totals = $this->price(array_column($lines, 'line_total'), $method, $coupon, $customer['country'], $weight, null, $vat['reverse_charge']);
            foreach ($lines as $i => $line) {
                $lines[$i]['tax_amount'] = $totals['item_taxes'][$i] ?? 0.0;
            }
            // The gift card is spent last (after variants and the coupon): it covers what it can, the rest is paid normally.
            $gift = $giftCardCode !== '' ? $this->giftCards->claim($giftCardCode, $totals['total']) : null;
            $giftAmount = $gift !== null ? $gift['amount'] : 0.0;
            $total = round($totals['total'] - $giftAmount, 2);
            $orderNumber = 'ORD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

            $orderId = $this->orders->insert([
                'order_number'       => $orderNumber,
                'customer_id'        => $customer['customer_id'] ?? null,
                'locale'             => $this->translator->locale(),
                'email'              => $customer['email'],
                'phone'              => $customer['phone'] ?: null,
                'ship_name'          => $customer['name'],
                'ship_address1'      => $customer['address1'],
                'ship_address2'      => $customer['address2'] ?: null,
                'ship_city'          => $customer['city'],
                'ship_state'         => $customer['state'] ?: null,
                'ship_postal_code'   => $customer['postal_code'],
                'ship_country'       => $customer['country'],
                'shipping_method'    => $method['code'],
                'shipping_label'     => $this->shipping->label($method),
                'payment_method'     => $paymentMethod,
                'subtotal'           => $totals['subtotal'],
                'shipping_cost'      => $totals['shipping'],
                'weight_grams'       => $weight,
                'coupon_code'        => $coupon !== null ? (string) $coupon['code'] : null,
                'discount_amount'    => $totals['discount'],
                'prices_include_tax' => $totals['prices_include_tax'] ? 1 : 0,
                'tax_rate'           => $totals['tax_rate'],
                'tax_amount'         => $totals['tax_amount'],
                'vat_id'             => $vat['vat_id'] !== '' ? $vat['vat_id'] : null,
                'vat_reverse_charge' => $vat['reverse_charge'] ? 1 : 0,
                'gift_card_code'     => $giftAmount > 0.0 ? (string) $gift['card']['code'] : null,
                'gift_card_amount'   => $giftAmount,
                'gift_card_counted'  => $giftAmount > 0.0 ? 1 : 0,
                'total'              => $total,
                'stock_reserved'     => 1,
                'coupon_counted'     => $coupon !== null ? 1 : 0,
            ]);

            if ($gift !== null) {
                $this->giftCards->recordRedemption((int) $gift['card']['id'], $orderId, $giftAmount, $orderNumber);
            }
            foreach ($lines as $line) {
                $this->orders->addItem($orderId, $line);
                $this->variants->decrementStock($line['variant_id'], $line['quantity']);
            }

            return ['id' => $orderId, 'order_number' => $orderNumber, 'total' => $total, 'email' => $customer['email']];
        });
        $this->cache->bump(); // stock changed: listings must not keep showing the old stock

        return $placed;
    }
}
