<?php

declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\Database;
use App\Repository\OrderNoteRepository;
use App\Repository\OrderRepository;
use App\Repository\SettingRepository;
use App\Repository\VariantRepository;
use InvalidArgumentException;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Imports orders placed on a marketplace or delivery app (Glovo, Bolt Food, …)
 * from a normalized JSON payload, posted by a connector with an API key
 * (Settings → Marketplace orders). The marketplace already took the payment
 * and does the delivery, so an imported order:
 *   - uses the prices the customer paid at the marketplace, not the catalogue prices,
 *   - is recorded as paid, takes its stock, and sends no customer emails,
 *   - is idempotent per (source, external_id): a retry returns the existing order.
 * A SKU the store doesn't know rejects the whole order; too little stock does not
 * (the order exists at the marketplace), it leaves a note for staff instead.
 */
final class MarketplaceOrderImporter
{
    private const KEY_SETTING = 'marketplace_api_key_hash';
    private const MAX_ITEMS = 200;

    public function __construct(
        private readonly Database $db,
        private readonly OrderRepository $orders,
        private readonly VariantRepository $variants,
        private readonly OrderNoteRepository $notes,
        private readonly SettingRepository $settings,
        private readonly StoreSettings $store,
        private readonly LoggerInterface $logger,
        private readonly \App\Support\FileCache $cache,
    ) {
    }

    public function hasKey(): bool
    {
        return $this->settings->get(self::KEY_SETTING) !== null;
    }

    /** Creates a new key (replacing any old one) and returns it — the only time it can be seen. */
    public function generateKey(): string
    {
        $key = 'mk_' . bin2hex(random_bytes(24));
        $this->settings->set(self::KEY_SETTING, hash('sha256', $key));

        return $key;
    }

    public function revokeKey(): void
    {
        $this->settings->set(self::KEY_SETTING, null);
    }

    public function keyIsValid(string $presented): bool
    {
        $stored = $this->settings->get(self::KEY_SETTING);

        return $stored !== null && $presented !== '' && hash_equals($stored, hash('sha256', $presented));
    }

    /**
     * @param array<string, mixed> $payload see docs in README → Marketplace orders
     * @return array{id: int, order_number: string, duplicate: bool}
     * @throws InvalidArgumentException with a list of problems in getMessage() separated by "; "
     */
    public function import(array $payload): array
    {
        $order = $this->validate($payload);

        $existing = $this->orders->findBySourceExternal($order['source'], $order['external_id']);
        if ($existing !== null) {
            return ['id' => (int) $existing['id'], 'order_number' => (string) $existing['order_number'], 'duplicate' => true];
        }

        $result = $this->db->transaction(function () use ($order): array {
            $lines = [];
            $short = [];
            $weight = 0;
            $ordered = $order['items'];
            usort($ordered, static fn (array $a, array $b): int => strcmp($a['sku'], $b['sku'])); // same lock order everywhere

            foreach ($ordered as $item) {
                $row = $this->variantBySku($item['sku']);
                if ($row === null) {
                    throw new InvalidArgumentException('Unknown SKU: ' . $item['sku']);
                }
                if ((int) $row['stock'] < $item['quantity']) {
                    $short[] = $item['sku'] . ' (' . $row['stock'] . ' in stock, ' . $item['quantity'] . ' ordered)';
                }
                $weight += (int) $row['weight_grams'] * $item['quantity'];
                $lines[] = [
                    'variant_id'   => (int) $row['id'],
                    'product_name' => (string) $row['product_name'],
                    'label'        => $row['label'] !== null ? (string) $row['label'] : null,
                    'unit'         => $row['unit'] !== null ? (string) $row['unit'] : null,
                    'unit_price'   => $item['unit_price'],
                    'quantity'     => $item['quantity'],
                    'line_total'   => round($item['unit_price'] * $item['quantity'], 2),
                ];
            }

            $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);
            $total = max(0.0, round($subtotal + $order['delivery_fee'] - $order['discount'], 2));
            $number = 'MKT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $c = $order['customer'];

            $orderId = $this->orders->insert([
                'order_number'       => $number,
                'source'             => $order['source'],
                'external_id'        => $order['external_id'],
                'locale'             => $this->store->language(),
                'email'              => $c['email'],
                'phone'              => $c['phone'],
                'ship_name'          => $c['name'],
                'ship_address1'      => $c['address1'],
                'ship_address2'      => $c['address2'],
                'ship_city'          => $c['city'],
                'ship_state'         => $c['state'],
                'ship_postal_code'   => $c['postal_code'],
                'ship_country'       => $c['country'],
                'shipping_method'    => 'marketplace',
                'shipping_label'     => $order['source_label'],
                'payment_method'     => 'marketplace',
                'status'             => 'paid',
                'payment_status'     => 'paid',
                'subtotal'           => $subtotal,
                'shipping_cost'      => $order['delivery_fee'],
                'weight_grams'       => $weight,
                'discount_amount'    => $order['discount'],
                'prices_include_tax' => 1,
                'tax_rate'           => $order['tax_rate'],
                'tax_amount'         => $order['tax_amount'],
                'total'              => $total,
                'stock_reserved'     => 1,
                'coupon_counted'     => 0,
                'created_at'         => $order['placed_at'],
            ]);
            foreach ($lines as $line) {
                $this->orders->addItem($orderId, $line);
                $this->variants->decrementStock($line['variant_id'], $line['quantity']);
            }

            $this->notes->add($orderId, 'marketplace', 'Imported from ' . $order['source_label'] . ' (order ' . $order['external_id'] . ').' . ($order['note'] !== '' ? "\nCustomer note: " . $order['note'] : ''));
            if ($short !== []) {
                $this->notes->add($orderId, 'marketplace', 'Not enough stock for: ' . implode(', ', $short) . '. Stock was set to 0 — check this order.');
            }

            return ['id' => $orderId, 'order_number' => $number, 'duplicate' => false];
        });

        $this->cache->bump();
        $this->logger->info('Marketplace order imported', ['source' => $order['source'], 'external_id' => $order['external_id'], 'order' => $result['order_number']]);

        return $result;
    }

    /**
     * Checks and normalizes the payload.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     * @throws InvalidArgumentException
     */
    private function validate(array $payload): array
    {
        $errors = [];
        $source = strtolower(trim((string) ($payload['source'] ?? '')));
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,29}$/', $source) !== 1) {
            $errors[] = 'source must be 2-30 characters: letters, digits, "-" or "_"';
        }
        $externalId = trim((string) ($payload['external_id'] ?? ''));
        if ($externalId === '' || mb_strlen($externalId) > 80) {
            $errors[] = 'external_id is required (max 80 characters)';
        }
        $currency = strtoupper(trim((string) ($payload['currency'] ?? $this->store->currency())));
        if ($currency !== $this->store->currency()) {
            $errors[] = 'currency must be ' . $this->store->currency();
        }

        $items = [];
        $rawItems = $payload['items'] ?? null;
        if (!is_array($rawItems) || $rawItems === [] || count($rawItems) > self::MAX_ITEMS) {
            $errors[] = 'items must be a list of 1 to ' . self::MAX_ITEMS . ' lines';
        } else {
            foreach (array_values($rawItems) as $i => $item) {
                $sku = is_array($item) ? trim((string) ($item['sku'] ?? '')) : '';
                $quantity = is_array($item) ? filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]) : false;
                $price = is_array($item) && is_numeric($item['unit_price'] ?? null) ? round((float) $item['unit_price'], 2) : null;
                if ($sku === '' || $quantity === false || $price === null || $price < 0 || $price > VariantRepository::MAX_PRICE) {
                    $errors[] = 'items[' . $i . '] needs sku, quantity (whole number of 1 or more) and unit_price (0 or more)';
                    continue;
                }
                // The same SKU twice is one line.
                if (isset($items[$sku]) && abs($items[$sku]['unit_price'] - $price) > 0.004) {
                    $errors[] = 'items[' . $i . '] repeats ' . $sku . ' with a different unit_price';
                    continue;
                }
                $items[$sku] = ['sku' => $sku, 'quantity' => ($items[$sku]['quantity'] ?? 0) + $quantity, 'unit_price' => $price];
            }
        }

        $money = static fn (mixed $v): ?float => $v === null || $v === '' ? 0.0 : (is_numeric($v) && (float) $v >= 0 ? round((float) $v, 2) : null);
        $deliveryFee = $money($payload['delivery_fee'] ?? null);
        $discount = $money($payload['discount'] ?? null);
        $taxAmount = $money($payload['tax_amount'] ?? null);
        $taxRate = $money($payload['tax_rate'] ?? null);
        foreach (['delivery_fee' => $deliveryFee, 'discount' => $discount, 'tax_amount' => $taxAmount, 'tax_rate' => $taxRate] as $name => $value) {
            if ($value === null) {
                $errors[] = $name . ' must be a number of 0 or more';
            }
        }

        $c = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
        $text = static fn (string $k, int $max, string $fallback = ''): string => mb_substr(trim((string) ($c[$k] ?? '')), 0, $max) ?: $fallback;
        $email = trim((string) ($c['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'customer.email is not a valid address';
            $email = '';
        }

        $placed = null;
        if (!empty($payload['placed_at'])) {
            $ts = strtotime((string) $payload['placed_at']);
            if ($ts === false || $ts > time() + 86400) {
                $errors[] = 'placed_at must be a past date/time (ISO 8601)';
            } else {
                $placed = date('Y-m-d H:i:s', $ts);
            }
        }

        if ($errors !== []) {
            throw new InvalidArgumentException(implode('; ', $errors));
        }

        return [
            'source'       => $source,
            'source_label' => ucwords(str_replace(['-', '_'], ' ', $source)),
            'external_id'  => $externalId,
            'items'        => array_values($items),
            'delivery_fee' => (float) $deliveryFee,
            'discount'     => (float) $discount,
            'tax_amount'   => (float) $taxAmount,
            'tax_rate'     => min(100.0, (float) $taxRate),
            'placed_at'    => $placed ?? date('Y-m-d H:i:s'),
            'note'         => mb_substr(trim((string) ($payload['note'] ?? '')), 0, 500),
            'customer'     => [
                'name'        => $text('name', 150, 'Marketplace customer'),
                'email'       => $email !== '' ? mb_strtolower($email) : 'no-email@marketplace.invalid',
                'phone'       => $text('phone', 40) ?: null,
                'address1'    => $text('address1', 190, '—'),
                'address2'    => $text('address2', 190) ?: null,
                'city'        => $text('city', 100, '—'),
                'state'       => $text('state', 100) ?: null,
                'postal_code' => $text('postal_code', 20, '—'),
                'country'     => $text('country', 100, '—'),
            ],
        ];
    }

    /** Locks the variant (and returns it with its product name), or null for an unknown SKU. @return array<string, mixed>|null */
    private function variantBySku(string $sku): ?array
    {
        $stmt = $this->db->pdo()->prepare('
            SELECT v.id, v.label, v.unit, v.stock, v.weight_grams, p.name AS product_name
            FROM product_variants v JOIN products p ON p.id = v.product_id
            WHERE v.sku = :sku FOR UPDATE
        ');
        $stmt->execute(['sku' => $sku]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
