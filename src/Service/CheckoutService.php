<?php

declare(strict_types=1);

namespace App\Service;

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
     * @return array{subtotal: float, discount: float, shipping: float, prices_include_tax: bool, tax_rate: float, tax_amount: float, item_taxes: list<float>, shipping_tax: float, total: float}
     */
    public function price(array $lineTotals, ?array $method, ?array $coupon, string $country): array
    {
        $subtotal = round(array_sum($lineTotals), 2);
        $shipping = $method !== null ? $this->shippingFor($method, $subtotal, $coupon) : 0.0;
        $discount = $coupon !== null ? $this->coupons->discount($coupon, $subtotal, $shipping) : ['items' => 0.0, 'shipping' => 0.0];

        return $this->tax->totals($lineTotals, $discount['items'], $shipping, $discount['shipping'], $country);
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
    public function shippingFor(array $method, float $subtotal, ?array $coupon): float
    {
        $itemsDiscount = $coupon !== null ? $this->coupons->discount($coupon, $subtotal, 0.0)['items'] : 0.0;

        return $this->shipping->priceFor($method, round($subtotal - $itemsDiscount, 2));
    }

    /**
     * @param array{name: string, email: string, phone: string, address1: string, address2: string, city: string, state: string, postal_code: string, country: string, customer_id?: ?int} $customer
     *        customer_id: the signed-in customer account (omitted / null = guest checkout)
     * @param list<array<string, mixed>> $items cart lines (CartService::items())
     * @param string                     $couponCode discount code ('' = none)
     * @return array{id: int, order_number: string, total: float, email: string}
     * @throws RuntimeException with a message safe to show the customer
     */
    public function placeOrder(array $customer, array $items, string $shippingCode, string $paymentMethod, string $couponCode = ''): array
    {
        if ($items === []) {
            throw new RuntimeException('Your cart is empty.');
        }

        $method = $this->shipping->available($shippingCode, $customer['country'])
            ?? throw new RuntimeException('That shipping method is not available for your country.');

        // Lock in a fixed order so two checkouts with the same items can't deadlock.
        usort($items, static fn (array $a, array $b) => (int) $a['variant_id'] <=> (int) $b['variant_id']);

        return $this->db->transaction(function () use ($customer, $items, $method, $paymentMethod, $couponCode): array {
            $subtotal = 0.0;
            $lines = [];
            foreach ($items as $item) {
                $quantity = (int) $item['quantity'];
                $row = $this->variants->lockForUpdate((int) $item['variant_id']);
                if ($row === null || !(int) $row['available']) {
                    throw new RuntimeException($item['product_name'] . ' is no longer available.');
                }

                // A variant without a real price isn't for sale — treat it as out of stock.
                $price = (float) $row['price'];
                $available = $price <= 0.0 ? 0 : (int) $row['stock'];
                if ($quantity < 1 || $available < $quantity) {
                    throw new RuntimeException("Not enough stock for {$row['product_name']} — only {$available} left.");
                }
                if (abs($price - (float) $item['price']) >= 0.005) {
                    throw new RuntimeException("The price of {$row['product_name']} has changed. Please review your order and place it again.");
                }

                $lineTotal = round($price * $quantity, 2);
                $subtotal += $lineTotal;
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
            $totals = $this->price(array_column($lines, 'line_total'), $method, $coupon, $customer['country']);
            foreach ($lines as $i => $line) {
                $lines[$i]['tax_amount'] = $totals['item_taxes'][$i] ?? 0.0;
            }
            $total = $totals['total'];
            $orderNumber = 'ORD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

            $orderId = $this->orders->insert([
                'order_number'       => $orderNumber,
                'customer_id'        => $customer['customer_id'] ?? null,
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
                'coupon_code'        => $coupon !== null ? (string) $coupon['code'] : null,
                'discount_amount'    => $totals['discount'],
                'prices_include_tax' => $totals['prices_include_tax'] ? 1 : 0,
                'tax_rate'           => $totals['tax_rate'],
                'tax_amount'         => $totals['tax_amount'],
                'total'              => $total,
                'stock_reserved'     => 1,
                'coupon_counted'     => $coupon !== null ? 1 : 0,
            ]);

            foreach ($lines as $line) {
                $this->orders->addItem($orderId, $line);
                $this->variants->decrementStock($line['variant_id'], $line['quantity']);
            }

            return ['id' => $orderId, 'order_number' => $orderNumber, 'total' => $total, 'email' => $customer['email']];
        });
    }
}
