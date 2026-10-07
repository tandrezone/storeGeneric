<?php

declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\Database;
use App\Repository\OrderRepository;
use App\Repository\VariantRepository;
use RuntimeException;

/**
 * Turns a cart into an order: re-checks stock with row locks, prices the
 * order (items + shipping) and writes it, all in one transaction. Stock is
 * only decremented later, when the payment is confirmed (PaymentRecorder).
 */
final class CheckoutService
{
    public function __construct(
        private readonly Database $db,
        private readonly VariantRepository $variants,
        private readonly OrderRepository $orders,
        private readonly ShippingService $shipping,
    ) {
    }

    /**
     * @param array{name: string, email: string, phone: string, address1: string, address2: string, city: string, state: string, postal_code: string, country: string} $customer
     * @param list<array<string, mixed>> $items cart lines (CartService::items())
     * @return array{id: int, order_number: string, total: float, email: string}
     * @throws RuntimeException with a message safe to show the customer
     */
    public function placeOrder(array $customer, array $items, string $shippingCode, string $paymentMethod): array
    {
        if ($items === []) {
            throw new RuntimeException('Your cart is empty.');
        }

        $method = $this->shipping->available($shippingCode, $customer['country'])
            ?? throw new RuntimeException('That shipping method is not available for your country.');

        return $this->db->transaction(function () use ($customer, $items, $method, $paymentMethod): array {
            $subtotal = 0.0;
            foreach ($items as $item) {
                $row = $this->variants->lockForUpdate((int) $item['variant_id'])
                    ?? throw new RuntimeException($item['product_name'] . ' is no longer available.');

                // A variant without a real price isn't for sale — treat it as out of stock.
                $available = (float) $row['price'] <= 0.0 ? 0 : (int) $row['stock'];
                if ($available < (int) $item['quantity']) {
                    throw new RuntimeException("Not enough stock for {$item['product_name']} — only {$available} left.");
                }
                $subtotal += (float) $item['price'] * (int) $item['quantity'];
            }

            $subtotal = round($subtotal, 2);
            $shippingCost = $this->shipping->priceFor($method, $subtotal);
            $total = round($subtotal + $shippingCost, 2);
            $orderNumber = 'ORD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

            $orderId = $this->orders->insert([
                'order_number'     => $orderNumber,
                'email'            => $customer['email'],
                'phone'            => $customer['phone'] ?: null,
                'ship_name'        => $customer['name'],
                'ship_address1'    => $customer['address1'],
                'ship_address2'    => $customer['address2'] ?: null,
                'ship_city'        => $customer['city'],
                'ship_state'       => $customer['state'] ?: null,
                'ship_postal_code' => $customer['postal_code'],
                'ship_country'     => $customer['country'],
                'shipping_method'  => $method['code'],
                'shipping_label'   => $this->shipping->label($method),
                'payment_method'   => $paymentMethod,
                'subtotal'         => $subtotal,
                'shipping_cost'    => $shippingCost,
                'total'            => $total,
            ]);

            foreach ($items as $item) {
                $this->orders->addItem($orderId, [
                    'variant_id'   => $item['variant_id'],
                    'product_name' => $item['product_name'],
                    'label'        => $item['label'] !== null ? (string) $item['label'] : null,
                    'unit'         => $item['unit'] !== null ? (string) $item['unit'] : null,
                    'unit_price'   => $item['price'],
                    'quantity'     => $item['quantity'],
                    'line_total'   => round((float) $item['price'] * (int) $item['quantity'], 2),
                ]);
            }

            return ['id' => $orderId, 'order_number' => $orderNumber, 'total' => $total, 'email' => $customer['email']];
        });
    }
}
