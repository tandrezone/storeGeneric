<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Money;

/**
 * Order totals with VAT. The discount comes off the items subtotal first and
 * VAT is computed on what is left (plus shipping when shipping is taxed),
 * using the rate for the customer's shipping country (TaxSettings).
 *
 *  - prices include VAT: the total doesn't change, the VAT part is extracted
 *    (base × rate / (100 + rate));
 *  - prices exclude VAT: base × rate / 100 is added to the total.
 *
 * Amounts are rounded to the currency's minor unit (at most 2 decimals, the
 * precision of the DECIMAL(10,2) order columns) and never go below 0.
 */
final class TaxCalculator
{
    public function __construct(
        private readonly TaxSettings $settings,
        private readonly StoreSettings $store,
    ) {
    }

    /**
     * The returned `discount` is the items discount plus the shipping discount,
     * so subtotal − discount + shipping (+ VAT when added on top) = total.
     *
     * @param list<float> $lineTotals       item line totals as priced in the shop
     * @param float       $itemsDiscount    amount off the items subtotal (capped at the subtotal)
     * @param float       $shipping         shipping cost of the chosen method
     * @param float       $shippingDiscount amount off the shipping (free-shipping codes; capped at the shipping)
     * @return array{subtotal: float, discount: float, shipping: float, prices_include_tax: bool, tax_rate: float, tax_amount: float, item_taxes: list<float>, shipping_tax: float, total: float}
     */
    public function totals(array $lineTotals, float $itemsDiscount, float $shipping, float $shippingDiscount, string $country): array
    {
        $decimals = $this->decimals();
        $subtotal = round(array_sum($lineTotals), $decimals);
        $itemsDiscount = round(min(max(0.0, $itemsDiscount), $subtotal), $decimals);
        $shipping = round(max(0.0, $shipping), $decimals);
        $shippingDiscount = round(min(max(0.0, $shippingDiscount), $shipping), $decimals);
        $rate = $this->settings->rateFor($country);
        $inclusive = $this->settings->pricesIncludeTax();

        // Share of the tax base in the amount: rate/(100+rate) when VAT is inside the price, rate/100 on top.
        $factor = $rate <= 0.0 ? 0.0 : ($inclusive ? $rate / (100 + $rate) : $rate / 100);
        $itemsBase = $subtotal - $itemsDiscount;
        $shippingBase = $shipping - $shippingDiscount;
        $itemsTax = round($itemsBase * $factor, $decimals);
        $shippingTax = $this->settings->shippingTaxed() ? round($shippingBase * $factor, $decimals) : 0.0;
        $tax = round($itemsTax + $shippingTax, $decimals);

        $total = $itemsBase + $shippingBase + ($inclusive ? 0.0 : $tax);

        return [
            'subtotal'           => $subtotal,
            'discount'           => round($itemsDiscount + $shippingDiscount, $decimals),
            'shipping'           => $shipping,
            'prices_include_tax' => $inclusive,
            'tax_rate'           => $rate,
            'tax_amount'         => $tax,
            'item_taxes'         => $this->spread($lineTotals, $subtotal, $itemsTax, $decimals),
            'shipping_tax'       => $shippingTax,
            'total'              => round(max(0.0, $total), $decimals),
        ];
    }

    /**
     * Splits the items' VAT over the lines in proportion to their price (so the
     * discount is shared the same way); the last line takes the rounding rest.
     *
     * @param list<float> $lineTotals
     * @return list<float>
     */
    private function spread(array $lineTotals, float $subtotal, float $itemsTax, int $decimals): array
    {
        $taxes = [];
        $left = $itemsTax;
        $last = count($lineTotals) - 1;
        foreach ($lineTotals as $i => $line) {
            if ($i === $last) {
                $share = $left;
            } else {
                $share = $subtotal > 0.0 ? round($itemsTax * $line / $subtotal, $decimals) : 0.0;
            }
            $taxes[] = round($share, $decimals);
            $left = round($left - $share, $decimals);
        }

        return $taxes;
    }

    private function decimals(): int
    {
        return min(2, Money::exponent($this->store->currency()));
    }
}
