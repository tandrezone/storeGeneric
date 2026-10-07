<?php

declare(strict_types=1);

namespace App\Service;

use App\Payment\PaymentRegistry;
use App\Repository\OrderRepository;
use App\Repository\ProductCsvRepository;
use App\Support\Csv;

/**
 * Admin CSV exports. Each method returns a rewindable stream (see Csv::open())
 * for App\Http\CsvDownload.
 *
 * Products: one row per variant, in the format ProductCsvImporter reads.
 * Orders: one row per order; columns added to `orders` later (e.g. tax or
 * discount totals) are appended automatically after the known ones.
 */
final class CsvExport
{
    /** Order columns, in export order (header name => orders column). */
    private const ORDER_COLUMNS = [
        'order_number'     => 'order_number',
        'created_at'       => 'created_at',
        'status'           => 'status',
        'payment_status'   => 'payment_status',
        'payment_method'   => 'payment_method',
        'email'            => 'email',
        'phone'            => 'phone',
        'name'             => 'ship_name',
        'address1'         => 'ship_address1',
        'address2'         => 'ship_address2',
        'city'             => 'ship_city',
        'state'            => 'ship_state',
        'postal_code'      => 'ship_postal_code',
        'country'          => 'ship_country',
        'shipping'         => 'shipping_label',
        'subtotal'         => 'subtotal',
        'shipping_cost'    => 'shipping_cost',
        'total'            => 'total',
        'carrier'          => 'carrier',
        'tracking_number'  => 'tracking_number',
        'shipped_at'       => 'shipped_at',
        'updated_at'       => 'updated_at',
    ];

    /** Internal order columns never exported. */
    private const ORDER_HIDDEN = ['id', 'stock_reserved', 'shipping_method', 'coupon_counted'];

    private const ITEM_COLUMNS = ['order_number', 'created_at', 'sku', 'product_name', 'label', 'unit', 'unit_price', 'quantity', 'line_total'];
    private const ITEM_HIDDEN = ['id', 'order_id', 'variant_id'];

    public function __construct(
        private readonly ProductCsvRepository $products,
        private readonly OrderRepository $orders,
        private readonly PaymentRegistry $payments,
        private readonly StoreSettings $store,
    ) {
    }

    /**
     * @param int|null $lowStockAtOrBelow only products with an active variant at or below this stock
     * @return resource
     */
    public function products(?string $status, ?int $lowStockAtOrBelow = null)
    {
        $handle = Csv::open(ProductCsvImporter::COLUMNS);
        foreach ($this->products->exportRows($status, $lowStockAtOrBelow) as $row) {
            Csv::writeRow($handle, [
                $row['product_id'],
                $row['name'],
                $row['category'],
                $row['status'],
                $row['short_description'],
                $row['sku'],
                $row['label'],
                $row['unit'],
                $row['price'] !== null ? (float) $row['price'] : null,
                $row['stock'],
                $row['active'],
            ]);
        }

        return $handle;
    }

    /**
     * @param array{status?: ?string, q?: string, from?: string, to?: string} $filters as in Admin → Orders
     * @return resource
     */
    public function orders(array $filters)
    {
        $currency = $this->store->currency();
        $handle = null;
        $extra = [];
        foreach ($this->orders->eachForAdmin($filters) as $order) {
            if ($handle === null) {
                $extra = $this->extraColumns($order, [...array_values(self::ORDER_COLUMNS), ...self::ORDER_HIDDEN]);
                $handle = Csv::open([...array_keys(self::ORDER_COLUMNS), 'payment', 'currency', ...$extra]);
            }
            $values = [];
            foreach (self::ORDER_COLUMNS as $column) {
                $values[] = $this->value($order, $column);
            }
            $values[] = $this->payments->label((string) $order['payment_method']);
            $values[] = $currency;
            foreach ($extra as $column) {
                $values[] = $this->value($order, $column);
            }
            Csv::writeRow($handle, $values);
        }

        return $handle ?? Csv::open([...array_keys(self::ORDER_COLUMNS), 'payment', 'currency']);
    }

    /**
     * One row per order line of the orders matching $filters.
     *
     * @param array{status?: ?string, q?: string, from?: string, to?: string} $filters
     * @return resource
     */
    public function orderItems(array $filters)
    {
        $handle = null;
        $extra = [];
        foreach ($this->orders->eachItemForAdmin($filters) as $item) {
            if ($handle === null) {
                $extra = $this->extraColumns($item, [...self::ITEM_COLUMNS, ...self::ITEM_HIDDEN]);
                $handle = Csv::open([...self::ITEM_COLUMNS, ...$extra]);
            }
            $values = [];
            foreach ([...self::ITEM_COLUMNS, ...$extra] as $column) {
                $values[] = $this->value($item, $column);
            }
            Csv::writeRow($handle, $values);
        }

        return $handle ?? Csv::open(self::ITEM_COLUMNS);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $known
     * @return list<string> columns of $row not covered by $known
     */
    private function extraColumns(array $row, array $known): array
    {
        return array_values(array_diff(array_keys($row), $known));
    }

    /** @param array<string, mixed> $row */
    private function value(array $row, string $column): mixed
    {
        return $row[$column] ?? null;
    }
}
